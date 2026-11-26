"""Execute the dispatch workflow's guards with fake API/git responses; no network or install."""
import contextlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import textwrap
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = (ROOT / '.github/workflows/public-entry-verify.yml').read_text(encoding='utf-8')
REPOSITORY = 'Mellowing-Potato/Potato-GEO-SaaS'
COMMIT = 'a' * 40
WORKFLOW_COMMIT = 'b' * 40
VERSION = 'v0.1.1'


def step(name):
    match = re.search(r'^      - name: ' + re.escape(name) + r'\n(.*?)(?=^      - name: |\Z)',
                      WORKFLOW, re.MULTILINE | re.DOTALL)
    if not match:
        raise AssertionError('Missing workflow step: ' + name)
    return match.group(1)


def inline_python(name):
    body = step(name)
    return textwrap.dedent(body.split('        run: |\n', 1)[1])


def load_inline(name):
    namespace = {'__name__': 'offline_workflow_fixture'}
    exec(compile(inline_python(name), '<workflow Python step>', 'exec'), namespace)
    return namespace


def response(kind='commit', sha=COMMIT, ref=True):
    record = {'object': {'type': kind, 'sha': sha}}
    if ref:
        record['ref'] = 'refs/tags/' + VERSION
    return SimpleNamespace(returncode=0, stdout=json.dumps(record), stderr='')


class PublicEntryVerifyWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.guard = load_inline('Validate published tag and source')
        self.error = self.guard['GuardError']
        self.environment = {
            'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'github-hosted',
            'GITHUB_REPOSITORY': REPOSITORY, 'GITHUB_EVENT_NAME': 'workflow_dispatch',
            'GITHUB_REF': 'refs/heads/main', 'GITHUB_REF_TYPE': 'branch', 'GITHUB_REF_NAME': 'main',
            'GITHUB_WORKFLOW_REF': REPOSITORY + '/.github/workflows/public-entry-verify.yml@refs/heads/main',
            'GITHUB_SHA': WORKFLOW_COMMIT, 'VERSION': VERSION, 'SOURCE_COMMIT': COMMIT,
        }

    def test_workflow_requires_inputs_and_four_fresh_hosted_runner_scenarios(self):
        self.assertIn('on:\n  workflow_dispatch:\n    inputs:\n', WORKFLOW)
        version = WORKFLOW.split('      version:\n', 1)[1].split('      source_commit:\n', 1)[0]
        source = WORKFLOW.split('      source_commit:\n', 1)[1].split('\npermissions:', 1)[0]
        self.assertIn('required: true', version)
        self.assertIn('default: v0.1.1', version)
        self.assertIn('required: true', source)
        self.assertNotIn('default:', source)
        self.assertIn('os: [macos-15, windows-2025]', WORKFLOW)
        self.assertIn('scenario: [fresh, interrupted]', WORKFLOW)
        self.assertIn('runs-on: ${{ matrix.os }}', WORKFLOW)
        self.assertIn('fail-fast: false', WORKFLOW)
        self.assertIn('timeout-minutes: 30', WORKFLOW)
        self.assertIn('permissions:\n  contents: read\n', WORKFLOW)
        self.assertNotIn('contents: write', WORKFLOW)
        self.assertIn('actions/setup-python@6d4fd22b20465ccc0f7354476588e8df7c9ca0e0', WORKFLOW)
        self.assertIn("python-version: '3.12'", WORKFLOW)
        self.assertLess(WORKFLOW.index('Validate published tag and source'), WORKFLOW.index('Fetch exact workflow source'))
        self.assertLess(WORKFLOW.index('Fetch exact workflow source'), WORKFLOW.index('Verify standard published macOS'))

    def test_system_context_is_never_rewritten_and_inputs_do_not_enter_code(self):
        self.assertFalse(re.search(r'^\s+GITHUB_[A-Z_]+\s*:', WORKFLOW, re.MULTILINE))
        self.assertNotIn('GITHUB_ENV', WORKFLOW)
        self.assertNotIn('gh release', WORKFLOW)
        self.assertNotIn('secrets.', WORKFLOW)
        self.assertEqual(WORKFLOW.count('GH_TOKEN:'), 1)
        for name in ('Validate published tag and source', 'Fetch exact workflow source'):
            self.assertNotIn('${{', inline_python(name))
        for platform, shell, prefix in (('macOS', 'bash', ''), ('Windows', 'pwsh', 'env:')):
            body = step('Verify standard published ' + platform + ' reader command')
            self.assertIn("if: runner.os == '" + platform + "'", body)
            self.assertIn('shell: ' + shell, body)
            self.assertIn('VERSION: ${{ inputs.version }}', body)
            self.assertIn('SOURCE_COMMIT: ${{ inputs.source_commit }}', body)
            self.assertIn('SCENARIO: ${{ matrix.scenario }}', body)
            command = body.split('        run:', 1)[1]
            for option, variable in (('version', 'VERSION'), ('scenario', 'SCENARIO'), ('source-commit', 'SOURCE_COMMIT')):
                self.assertIn('--' + option + ' "$' + prefix + variable + '"', command)
            for forbidden in ('--port', '-Port ', '--instance', '--program-home', '--no-browser', '${{'):
                self.assertNotIn(forbidden, command)
        self.assertIn('if ($LASTEXITCODE -ne 0)', step('Verify standard published Windows reader command'))

    def test_only_exact_main_dispatch_context_can_reach_the_tag_lookup(self):
        self.guard['validate_context'](self.environment)
        for key in tuple(self.environment):
            if key in ('VERSION', 'SOURCE_COMMIT'):
                continue
            with self.subTest(key=key):
                environment = {**self.environment, key: 'rejected-context-fixture'}
                with patch.dict(os.environ, environment, clear=True), patch.object(subprocess, 'run') as api:
                    with contextlib.redirect_stdout(io.StringIO()):
                        self.assertEqual(self.guard['main'](), 1)
                api.assert_not_called()

    def test_lightweight_tag_uses_fixed_repository_read_only_get(self):
        with patch.object(subprocess, 'run', return_value=response()) as api:
            self.guard['validate_remote_tag'](VERSION, COMMIT)
        self.assertEqual(api.call_args.args[0], ['gh', 'api', '--method', 'GET',
            'https://api.github.com/repos/' + REPOSITORY + '/git/ref/tags/' + VERSION])
        self.assertEqual(api.call_args.kwargs['stdin'], subprocess.DEVNULL)
        self.assertEqual(api.call_args.kwargs['timeout'], 30)
        self.assertTrue(api.call_args.kwargs['capture_output'])

    def test_annotated_tags_are_peeled_up_to_five_layers(self):
        tag_shas = [str(index) * 40 for index in range(1, 6)]
        replies = [response('tag', tag_shas[0])]
        replies.extend(response('tag', sha, ref=False) for sha in tag_shas[1:])
        replies.append(response(ref=False))
        with patch.object(subprocess, 'run', side_effect=replies) as api:
            self.guard['validate_remote_tag'](VERSION, COMMIT)
        self.assertEqual(api.call_count, 6)
        self.assertEqual([call.args[0][-1].rsplit('/', 1)[1] for call in api.call_args_list[1:]], tag_shas)
        with patch.object(subprocess, 'run', return_value=response('tag', '1' * 40)) as api:
            with self.assertRaisesRegex(self.error, '^published_tag_peeling_rejected$'):
                self.guard['validate_remote_tag'](VERSION, COMMIT)
        self.assertEqual(api.call_count, 6)

    def test_remote_tag_mismatch_fails_instead_of_installing_same_version_old_source(self):
        with patch.object(subprocess, 'run', return_value=response(sha='c' * 40)):
            with self.assertRaisesRegex(self.error, '^published_tag_source_mismatch$'):
                self.guard['validate_remote_tag'](VERSION, COMMIT)

    def test_invalid_inputs_cannot_be_interpolated_into_an_api_endpoint(self):
        for version, commit in (("v0.1.1';ignored", COMMIT), ('v0.1.1\n', COMMIT),
                                ('../v0.1.1', COMMIT), (VERSION, ''), (VERSION, 'A' * 40)):
            with self.subTest(version=version, commit=commit), patch.object(subprocess, 'run') as api:
                with self.assertRaises(self.error):
                    self.guard['validate_remote_tag'](version, commit)
                api.assert_not_called()

    def test_invalid_remote_objects_or_wrong_ref_cannot_pass(self):
        for record in ({}, {'object': []}, {'object': {'type': 'commit', 'sha': 'short'}},
                       {'ref': 'refs/tags/v0.0.0', 'object': {'type': 'commit', 'sha': COMMIT}},
                       {'ref': 'refs/tags/' + VERSION, 'object': {'type': 'tree', 'sha': COMMIT}}):
            with self.subTest(record=record), patch.object(subprocess, 'run', return_value=SimpleNamespace(
                    returncode=0, stdout=json.dumps(record), stderr='')):
                with self.assertRaises(self.error):
                    self.guard['validate_remote_tag'](VERSION, COMMIT)

    def test_remote_failures_are_redacted_and_cannot_report_verified(self):
        secret = 'sk-test-private-api-diagnostic'
        cases = [SimpleNamespace(returncode=1, stdout=secret, stderr=secret),
                 SimpleNamespace(returncode=0, stdout=secret, stderr=''),
                 subprocess.TimeoutExpired(secret, 30), OSError(secret)]
        for result in cases:
            mock = {'side_effect': result} if isinstance(result, Exception) else {'return_value': result}
            with self.subTest(case=type(result).__name__), patch.dict(os.environ, self.environment, clear=True), \
                    patch.object(subprocess, 'run', **mock):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    self.assertEqual(self.guard['main'](), 1)
                self.assertNotIn(secret, output.getvalue())
                record = json.loads(output.getvalue())
                self.assertEqual(record['published_reference'], 'failed')
                self.assertIn(record['code'], ('published_tag_lookup_failed', 'published_tag_metadata_invalid'))

    def test_verified_guard_result_contains_no_token_paths_or_raw_response(self):
        with patch.dict(os.environ, self.environment, clear=True), \
                patch.object(subprocess, 'run', return_value=response()):
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                self.assertEqual(self.guard['main'](), 0)
        self.assertEqual(json.loads(output.getvalue()), {'published_reference': 'verified'})

    def test_checkout_fetches_workflow_sha_not_the_package_source_input(self):
        checkout = load_inline('Fetch exact workflow source')
        with patch.dict(os.environ, self.environment, clear=True), patch.object(subprocess, 'run',
                return_value=SimpleNamespace(stdout=WORKFLOW_COMMIT + '\n')) as git:
            self.assertEqual(checkout['main'](), 0)
        self.assertEqual(git.call_args_list[1].args[0], ['git', '-c', 'protocol.version=2', 'fetch',
            '--no-tags', '--depth=1', 'https://github.com/' + REPOSITORY + '.git', WORKFLOW_COMMIT])
        self.assertEqual(git.call_args_list[2].args[0], ['git', 'switch', '--detach', 'FETCH_HEAD'])
        self.assertEqual(git.call_args_list[3].args[0], ['git', 'rev-parse', 'HEAD'])
        for call in git.call_args_list:
            self.assertTrue(call.kwargs['check'])
            self.assertTrue(call.kwargs['capture_output'])
            self.assertLessEqual(call.kwargs['timeout'], 180)

    def test_wrong_checkout_commit_and_git_failure_are_not_accepted(self):
        checkout = load_inline('Fetch exact workflow source')
        with patch.dict(os.environ, self.environment, clear=True):
            with patch.object(subprocess, 'run', return_value=SimpleNamespace(stdout=COMMIT)):
                self.assertEqual(checkout['main'](), 1)
            with patch.object(subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'private-fixture')):
                self.assertEqual(checkout['main'](), 1)
            with patch.dict(os.environ, {'GITHUB_SHA': 'short'}), patch.object(subprocess, 'run') as git:
                self.assertEqual(checkout['main'](), 1)
                git.assert_not_called()


if __name__ == '__main__':
    unittest.main()
