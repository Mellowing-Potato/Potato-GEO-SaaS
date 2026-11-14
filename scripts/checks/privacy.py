#!/usr/bin/env python3
"""C0 category-based source privacy checks; never print matched values."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
# Reinstalled dependencies and local reports are not release source. Scan .env if present.
GENERATED = {'.git', 'node_modules', 'vendor', 'test-results', '__pycache__', '.dev-records'}
LOCKFILES = {'composer.lock', 'package-lock.json'}
# Approved public test destinations, not the operator's infrastructure.
TEST_ADDRESSES = {'1.1.1.1', '8.8.8.8', '1.2.3.4', '93.184.216.34'}
DOCUMENTATION_NETWORKS = tuple(ipaddress.ip_network(value) for value in (
    '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24',
))
RULES = {
    'public-ipv4': re.compile(r'(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])'),
    'ssh-command': re.compile(r'\bssh '),
    'root-login': re.compile(r'\broot@'),
    'phone': re.compile(r'(?<![\w])1[3-9]\d{9}(?![\w])'),
    'non-example-email': re.compile(r'@[A-Za-z0-9](?:[A-Za-z0-9.-]*\.)[A-Za-z]{2,}\b'),
    'non-test-key': re.compile(r'(?<![\w-])sk-(?!test-)[A-Za-z0-9_-]+'),
}


def sources(root=ROOT):
    for base, dirs, files in os.walk(root, followlinks=False):
        dirs[:] = sorted(d for d in dirs if d not in GENERATED)
        for name in sorted(files):
            path = Path(base) / name
            relative = path.relative_to(root).as_posix()
            if path.is_symlink() or relative.startswith('public/build/'):
                continue
            try:
                text = path.read_text(encoding='utf-8')
            except (UnicodeError, OSError):
                continue
            yield relative, text


def is_public_address(match, line):
    value = match.group()
    try:
        address = ipaddress.IPv4Address(value)
    except ipaddress.AddressValueError:
        return False
    # CIDR literals describe network ranges, not individual server destinations.
    if re.match(r'/(?:[0-9]|[12][0-9]|3[0-2])(?!\d)', line[match.end():]):
        return False
    # Only actual version syntax is exempt: never exempt every IP on a "version" line.
    prefix = line[:match.start()]
    if re.search(r'\b(?:Chrome|Chromium|Firefox|Version|Edg|OPR)/$', prefix):
        return False
    if re.search(r'"version(?:_normalized)?"\s*:\s*"v?$', prefix):
        return False
    if re.search(r'["\'][A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+["\']\s*:\s*["\'][~^<>=v ]*$', prefix):
        return False
    # ipaddress.is_global alone includes multicast on some Python versions.
    if (not address.is_global or address.is_private or address.is_loopback
            or address.is_reserved or address.is_multicast or address.is_link_local
            or address.is_unspecified):
        return False
    return value not in TEST_ADDRESSES and not any(address in network for network in DOCUMENTATION_NETWORKS)


def scan_text(relative, text):
    findings = []
    for number, line in enumerate(text.splitlines(), 1):
        for category, pattern in RULES.items():
            for match in pattern.finditer(line):
                value = match.group()
                if category == 'public-ipv4' and not is_public_address(match, line):
                    continue
                if category == 'phone' and re.fullmatch(r'1380000(?!0000)\d{4}', value):
                    continue
                if category == 'non-example-email':
                    # Dependency author metadata is publicly distributed; no per-line exceptions.
                    if relative in LOCKFILES or value.lower() in {'@example.com', '@example.test'}:
                        continue
                findings.append({'file': relative, 'line': number, 'category': category})
                break
    return findings


def load_denylist(root=ROOT):
    path = root / '.dev-records/private-denylist.txt'
    if not path.exists():
        return ()
    # Do not print, hash into public reports, or serialize private terms elsewhere.
    return tuple(value.strip() for value in path.read_text(encoding='utf-8').splitlines() if value.strip())


def private_findings(relative, text, terms):
    # Findings intentionally have no matched value or category: path and line only.
    return [{'file': relative, 'line': number}
            for number, line in enumerate(text.splitlines(), 1)
            if any(term.casefold() in line.casefold() for term in terms)]


def check_source(relative, text, terms):
    private = private_findings(relative, text, terms)
    categories = [] if relative.startswith('scripts/checks/') else scan_text(relative, text)
    return private + categories


def scan(root=ROOT):
    terms = load_denylist(root)
    return [finding for relative, text in sources(root)
            for finding in check_source(relative, text, terms)]


def scan_history(root=ROOT):
    terms = load_denylist(root)
    # Public-release authors may use GitHub's privacy-preserving noreply identity.
    # Parse identity fields separately: this exception never covers file content,
    # commit messages, other email domains, or the private denylist.
    metadata = subprocess.run(
        ['git', 'log', '--all', '-z', '--format=%an%x00%ae%x00%cn%x00%ce%x00%B'],
        cwd=root, text=True, encoding='utf-8', errors='replace', capture_output=True, check=True)
    fields = metadata.stdout.split('\0')[:-1]
    findings = []
    for index, value in enumerate(fields):
        identity_email = index % 5 in (1, 3)
        public_noreply = identity_email and re.fullmatch(
            r'(?:[0-9]+\+)?[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?@users\.noreply\.github\.com', value)
        # GitHub web edits use this public service identity as the committer.
        # Permit only that exact name/email pair in the committer fields;
        # authors, messages, file content and private terms remain checked.
        github_web_committer = (index % 5 == 3 and fields[index - 1] == 'GitHub'
                                and value == 'noreply@github.com')
        rows = private_findings('[commit metadata]', value, terms) if public_noreply or github_web_committer else check_source('[commit metadata]', value, terms)
        for finding in rows:
            finding['line'] += sum(v.count('\n') + 1 for v in fields[:index])
            findings.append(finding)
    # Scan additions AND removals in every reachable commit, not just HEAD's tree.
    # Metadata also passes the private denylist; git output never goes to the console.
    result = subprocess.run(
        ['git', '-c', 'core.quotePath=false', 'log', '--all', '--root', '-p',
         '--no-ext-diff', '--no-renames', '--format=commit %H'],
        cwd=root, text=True, encoding='utf-8', errors='replace', capture_output=True, check=True)
    relative = '[commit metadata]'
    old_line = new_line = 0
    for log_line, line in enumerate(result.stdout.splitlines(), 1):
        if line.startswith('commit '):
            relative = '[commit metadata]'
        if line.startswith('diff --git '):
            match = re.match(r'diff --git a/(.*?) b/(.*)$', line)
            if match:
                relative = match[2]
                if relative.startswith(('.dev-records/', 'docs/development/')):
                    findings.append({'file': relative, 'line': 0, 'category': 'private-record-in-history'})
        if line.startswith('+++ b/'):
            relative = line[6:]
        hunk = re.match(r'@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@', line)
        if hunk:
            old_line, new_line = map(int, hunk.groups())
            continue
        if line.startswith(('+++ ', '--- ', 'diff --git ', 'index ')):
            findings.extend({'file': relative, 'line': 0} for _ in [0]
                            if any(term.casefold() in line.casefold() for term in terms))
            continue
        if line.startswith(('+', '-', ' ')) and relative != '[commit metadata]':
            marker, content = line[0], line[1:]
            number = old_line if marker == '-' else new_line
            for finding in check_source(relative, content, terms):
                finding['line'] = number
                findings.append(finding)
            if marker != '+': old_line += 1
            if marker != '-': new_line += 1
        else:
            for finding in check_source(relative, line, terms):
                finding['line'] = log_line
                findings.append(finding)
    return findings


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', type=Path)
    parser.add_argument('--history', action='store_true', help='Scan git log --all -p, including removed lines and commit messages')
    args = parser.parse_args()
    findings = scan_history() if args.history else scan()
    rendered = json.dumps(findings, ensure_ascii=False, indent=2) + '\n'
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(rendered, encoding='utf-8')
    print(rendered, end='')
    raise SystemExit(bool(findings))
