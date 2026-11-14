#!/usr/bin/env python3
"""Build and run Linux tests in disposable Docker containers, without host DBs.

Requires Docker Buildx and Python 3. Run from any directory:
  python scripts/test-linux.py
  python scripts/test-linux.py --skip-build --image geo-saas:test
SQLite/JS run with no network. PostgreSQL has an internal-only network, no
published ports or host volumes. Only dependency installation has Internet access.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
POSTGRES_IMAGE = "pgvector/pgvector@sha256:1d533553fefe4f12e5d80c7b80622ba0c382abb5758856f52983d8789179f0fb"


def docker(*args, check=True, capture=False):
    return subprocess.run(["docker", *args], cwd=ROOT, check=check,
                          text=True, capture_output=capture)


def is_local_docker_endpoint(context_endpoint, environment):
    # Docker documents DOCKER_CONTEXT as taking precedence over DOCKER_HOST.
    endpoint = context_endpoint if environment.get("DOCKER_CONTEXT") else environment.get("DOCKER_HOST") or context_endpoint
    return endpoint.lower().startswith(("unix:///", "npipe:////./pipe/", "npipe:////localhost/pipe/"))


def main():
    # Windows pipe redirection otherwise uses the locale codec (often GBK),
    # which cannot represent the JavaScript tests' checkmarks and other Unicode.
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", default="geo-saas:test")
    parser.add_argument("--skip-build", action="store_true")
    parser.add_argument("--platform", choices=("linux/amd64", "linux/arm64"), help="Omit to test the Docker host architecture")
    parser.add_argument("--results", type=Path, default=ROOT / "test-results")
    args = parser.parse_args()
    # Refuse a remote Docker daemon; never run this harness against production.
    endpoint = docker("context", "inspect", "--format", "{{.Endpoints.docker.Host}}", capture=True).stdout.strip()
    if not is_local_docker_endpoint(endpoint, os.environ):
        raise RuntimeError("Only a local Docker socket is permitted.")
    if not args.skip_build:
        platform_args = ("--platform", args.platform) if args.platform else ()
        docker("buildx", "build", "--load", *platform_args, "--target", "test", "--build-context",
               "test-sources=./tests", "--tag", args.image, ".")
    if docker("image", "inspect", POSTGRES_IMAGE, check=False, capture=True).returncode != 0:
        docker("pull", POSTGRES_IMAGE)
    run_id = "geo-test-" + uuid.uuid4().hex[:12]
    network, database = run_id + "-net", run_id + "-db"
    containers = []
    network_created = False
    results = args.results.resolve() / run_id
    results.mkdir(parents=True, exist_ok=False)
    outcomes = {}

    def run_suite(suite, network_name, extra_env=()):
        name = run_id + "-" + suite
        containers.append(name)
        env_args = [item for entry in extra_env for item in ("-e", entry)]
        command = ["run", "--name", name, "--network", network_name,
                   "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true",
                   "--pids-limit", "256", "-e", "APP_ENV=testing", "-e", "AI_MOCK_MODE=true",
                   *env_args, args.image, suite]
        log_path = results / (suite + ".log")
        print(f"Running {suite}; log: {log_path}", flush=True)
        with log_path.open("w", encoding="utf-8") as log:
            process = subprocess.Popen(["docker", *command], cwd=ROOT, stdout=subprocess.PIPE,
                                       stderr=subprocess.STDOUT, text=True, encoding="utf-8", errors="replace")
            for line in process.stdout:
                print(line, end="", flush=True)
                log.write(line)
            exit_code = process.wait()
        copied = docker("cp", name + ":/app/test-results/.", str(results), check=False, capture=True)
        outcomes[suite] = {"exit_code": exit_code, "reports_copied": copied.returncode == 0}

    try:
        run_suite("sqlite", "none")
        docker("network", "create", "--internal", network, capture=True)
        network_created = True
        containers.append(database)
        docker("run", "-d", "--name", database, "--network", network, "--network-alias", "postgres",
               "-e", "POSTGRES_DB=geo_test", "-e", "POSTGRES_USER=geo_test",
               "-e", "POSTGRES_PASSWORD=geo_test_disposable", POSTGRES_IMAGE, capture=True)
        for _ in range(60):
            if docker("exec", database, "pg_isready", "-U", "geo_test", "-d", "geo_test",
                      check=False, capture=True).returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError("Disposable PostgreSQL did not become ready.")
        run_suite("postgres", network, (
            "DB_CONNECTION=pgsql", "DB_HOST=postgres", "DB_PORT=5432", "DB_DATABASE=geo_test",
            "DB_USERNAME=geo_test", "DB_PASSWORD=geo_test_disposable", "DB_URL=",
        ))
    finally:
        # Remove only names generated in this invocation. No prune, volumes or host deletion.
        for name in reversed(containers):
            docker("rm", "-f", "-v", name, check=False, capture=True)
        if network_created:
            docker("network", "rm", network, check=False, capture=True)
        for suite in outcomes:
            report = results / ("phpunit-" + suite + ".xml")
            if report.is_file():
                cases = ET.parse(report).getroot().findall(".//testcase")
                outcomes[suite]["phpunit"] = {
                    "tests": len(cases),
                    "assertions": sum(int(c.get("assertions", "0")) for c in cases),
                    "failures": sum(c.find("failure") is not None for c in cases),
                    "errors": sum(c.find("error") is not None for c in cases),
                    "skipped": sum(c.find("skipped") is not None for c in cases),
                }
        (results / "summary.json").write_text(json.dumps(outcomes, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"results": str(results), "suites": outcomes}, indent=2), flush=True)
    return 0 if len(outcomes) == 2 and all(v["exit_code"] == 0 and v["reports_copied"] for v in outcomes.values()) else 1


if __name__ == "__main__":
    sys.exit(main())
