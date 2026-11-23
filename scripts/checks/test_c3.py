"""Guard public repository exemptions and the runtime/source scan boundary."""
from pathlib import Path
import subprocess
import tempfile
import unittest

import c3
import privacy


class RepositoryExemptionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='c3-boundary-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def scan(self, text):
        (self.root / 'README.md').write_text(text, encoding='utf-8')
        return c3.scan(self.root)

    def test_exact_public_repository_is_allowed(self):
        self.assertEqual([], self.scan('https://github.com/Mellowing-Potato/Potato-GEO-SaaS\n'))

    def test_same_line_other_forbidden_content_is_rejected(self):
        self.assertEqual(
            [{'file': 'README.md', 'line': 1, 'category': 'private-model-protocol'}],
            self.scan('Mellowing-Potato/Potato-GEO-SaaS geo-text\n'),
        )

    def test_non_exempt_content_is_rejected(self):
        self.assertEqual(
            [{'file': 'README.md', 'line': 1, 'category': 'private-model-protocol'}],
            self.scan('fixture-potato\n'),
        )


class RuntimeSourceBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='c3-runtime-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def write(self, name, text='fixture-potato\n'):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding='utf-8')

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, capture_output=True, text=True, check=True)

    def initialize(self):
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Fixture Author')
        self.git('config', 'user.email', 'fixture@example.com')

    def test_git_pointer_and_generated_runtime_files_are_not_source(self):
        self.write('.git', 'gitdir: /tmp/fixture-potato/worktrees/example\n')
        for name in ['bootstrap/cache/services.php', 'storage/framework/views/compiled.php',
                     'storage/framework/cache/data/entry', 'storage/framework/sessions/entry',
                     'storage/logs/runtime.log']:
            self.write(name)
        self.assertEqual([], c3.scan(self.root))

    def test_real_linked_worktree_metadata_is_not_scanned(self):
        repo = self.root / 'fixture-potato'
        repo.mkdir()
        self.root = repo
        self.initialize()
        self.write('README.md', 'safe fixture\n')
        self.git('add', 'README.md')
        self.git('commit', '-m', 'fixture')
        linked = repo.parent / 'linked'
        self.git('worktree', 'add', '--detach', str(linked), 'HEAD')
        self.assertTrue((linked / '.git').is_file())
        self.assertEqual([], c3.scan(linked))

    def test_tracked_file_in_runtime_directory_is_still_scanned(self):
        self.initialize()
        self.write('.gitignore', '/storage/framework/views/\n')
        self.write('storage/framework/views/committed.php')
        self.git('add', '-f', 'storage/framework/views/committed.php')
        self.assertEqual(
            [{'file': 'storage/framework/views/committed.php', 'line': 1,
              'category': 'private-model-protocol'}], c3.scan(self.root),
        )

    def test_untracked_ignored_application_source_is_still_scanned(self):
        self.initialize()
        self.write('.gitignore', '/app/ignored.php\n')
        self.write('app/ignored.php')
        self.assertEqual(
            [{'file': 'app/ignored.php', 'line': 1, 'category': 'private-model-protocol'}],
            c3.scan(self.root),
        )

    def test_local_environment_remains_in_the_privacy_scan(self):
        self.initialize()
        self.write('.gitignore', '.env\n')
        self.write('.env', 'MODEL_KEY=sk-' + 'deliberately-invalid-fixture\n')
        self.assertEqual(
            [{'file': '.env', 'line': 1, 'category': 'non-test-key'}], privacy.scan(self.root),
        )


if __name__ == '__main__':
    unittest.main()
