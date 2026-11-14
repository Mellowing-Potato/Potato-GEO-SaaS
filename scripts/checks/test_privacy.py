"""Regression checks for category boundaries; no live servers or credentials."""
import unittest
import subprocess
import tempfile
from pathlib import Path
from privacy import scan_text, scan, scan_history, private_findings, ROOT


class PrivacyRulesTest(unittest.TestCase):
    def categories(self, text, path='tests/fixtures/example.txt'):
        return {row['category'] for row in scan_text(path, text)}

    def test_addresses_are_classified_not_blanket_excluded(self):
        allowed = ['10.0.0.1', '127.1.2.3', '169.254.1.1', '100.64.0.1',
                   '224.0.0.1', '240.0.0.1', '192.0.2.1', '198.51.100.1',
                   '203.0.113.1', '1.1.1.1', '8.8.8.8', '1.2.3.4',
                   '93.184.216.34', '9.9.9.0/24', 'Chrome/130.0.0.0',
                   '"version": "1.2.3.4"', '"vendor/package": "^130.0.0.0"']
        for value in allowed:
            with self.subTest(value=value):
                self.assertEqual(set(), self.categories(value))
        self.assertIn('public-ipv4', self.categories('https://9.9.9.9/'))
        self.assertIn('public-ipv4', self.categories('version: https://9.9.9.9/'))
        self.assertIn('public-ipv4', self.categories('9.9.9.9/33'))

    def test_only_dependency_lockfiles_exempt_author_mail(self):
        for path in ['composer.lock', 'package-lock.json']:
            self.assertEqual(set(), self.categories('author@invalid.example', path))
        for value in ['user@example.com', 'user@example.test', '***@example.com']:
            self.assertEqual(set(), self.categories(value))
        self.assertIn('non-example-email', self.categories('user@invalid.example'))
        self.assertIn('non-example-email', self.categories('user@example.com.evil.test'))
        self.assertIn('non-example-email', self.categories('user@invalid.example', 'nested/composer.lock'))

    def test_credentials_and_ssh_stay_strict(self):
        self.assertEqual(set(), self.categories('sk-test-clearly-fake 13800000001 test-fake-token'))
        self.assertIn('non-test-key', self.categories('sk-' + 'deliberately-invalid-key'))
        self.assertIn('phone', self.categories('139' + '12345678'))
        self.assertIn('phone', self.categories('13800000000'))
        self.assertIn('ssh-command', self.categories('ssh example.test'))
        self.assertIn('root-login', self.categories('root@example.test'))
        self.assertEqual(set(), self.categories('class="md-sk--w80"'))
        self.assertIn('non-test-key', self.categories('class="sk-deliberately-invalid-key"'))

    def test_masked_prefix_has_no_exception_after_billing_removal(self):
        for path in ['tests/Feature/QingheServiceBindingTest.php']:
            self.assertIn('non-test-key', self.categories('sk-t********abcd', path))
            self.assertIn('non-test-key', self.categories('sk-t' + 'unmasked-fake-string', path))
        self.assertIn('non-test-key', self.categories('sk-t********abcd', 'app/Example.php'))
        self.assertIn('non-test-key', self.categories('sk-t********abcd', 'tests/Feature/GeoMvpWorkflowTest.php'))

    def test_report_does_not_echo_values(self):
        findings = scan_text('sample.txt', 'contact: fixture@invalid.example')
        self.assertEqual([{'file': 'sample.txt', 'line': 1, 'category': 'non-example-email'}], findings)


class PrivateHistoryTest(unittest.TestCase):
    def setUp(self):
        local = ROOT / 'test-results'
        local.mkdir(exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(prefix='privacy-history-', dir=local)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Fixture Author')
        self.git('config', 'user.email', 'fixture@example.com')
        (self.root / '.dev-records').mkdir()
        self.marker = 'fixture-' + 'private-customer'
        (self.root / '.dev-records/private-denylist.txt').write_text(self.marker + '\n')
        (self.root / '.gitignore').write_text('/.dev-records/\n')

    def git(self, *args):
        return subprocess.run(['git', *args], cwd=self.root, check=True, capture_output=True, text=True)

    def commit(self):
        self.git('add', '-A')
        self.git('commit', '-m', 'fixture change')

    def test_private_output_contains_only_location(self):
        self.assertEqual([{'file': 'sample.txt', 'line': 2}],
                         private_findings('sample.txt', 'safe\n' + self.marker, (self.marker,)))
        self.assertEqual([], scan(self.root))

    def test_removed_private_text_is_still_found_in_history(self):
        p = self.root / 'sample.txt'
        p.write_text('first\n' + self.marker + '\n')
        self.commit()
        p.write_text('clean\n')
        self.commit()
        self.assertEqual([], scan(self.root))
        findings = scan_history(self.root)
        self.assertTrue(any(f == {'file': 'sample.txt', 'line': 2} for f in findings))
        self.assertNotIn(self.marker, str(findings))

    def test_private_commit_message_is_not_missed(self):
        (self.root / 'sample.txt').write_text('clean\n')
        self.git('add', '-A')
        self.git('commit', '-m', self.marker)
        self.assertTrue(any(f['file'] == '[commit metadata]' for f in scan_history(self.root)))

    def test_github_noreply_is_allowed_only_in_identity_fields(self):
        email = '123+fixture-author@users.noreply.github.com'
        self.git('config', 'user.email', email)
        (self.root / 'sample.txt').write_text('clean\n')
        self.commit()
        self.assertEqual([], scan_history(self.root))
        self.git('commit', '--allow-empty', '-m', email)
        self.assertTrue(any(f.get('category') == 'non-example-email' for f in scan_history(self.root)))
        self.assertTrue(scan_text('sample.txt', email))

    def test_private_denylist_still_covers_noreply_identity(self):
        self.git('config', 'user.email', '123+' + self.marker + '@users.noreply.github.com')
        self.commit()
        self.assertTrue(any(f['file'] == '[commit metadata]' and 'category' not in f for f in scan_history(self.root)))

    def test_github_web_committer_does_not_expose_a_personal_email(self):
        self.git('config', 'user.name', 'GitHub')
        self.git('config', 'user.email', 'noreply@github.com')
        self.git('add', '-A')
        self.git('commit', '--author', 'Fixture Author <fixture@example.com>', '-m', 'web edit')
        self.assertEqual([], scan_history(self.root))
        self.git('commit', '--allow-empty', '--author', 'Fixture Author <fixture@example.com>',
                 '-m', 'noreply@github.com')
        self.assertTrue(any(f.get('category') == 'non-example-email' for f in scan_history(self.root)))
        self.assertTrue(scan_text('sample.txt', 'noreply@github.com'))

    def test_github_web_committer_exception_is_narrow_and_denylist_still_applies(self):
        self.git('config', 'user.name', 'GitHub')
        self.git('config', 'user.email', 'noreply@github.com')
        self.git('add', '-A')
        self.git('commit', '--author', 'Fixture Author <fixture@example.com>', '-m', 'web edit')
        (self.root / '.dev-records/private-denylist.txt').write_text('noreply@github.com\n')
        self.assertTrue(any(f['file'] == '[commit metadata]' and 'category' not in f for f in scan_history(self.root)))
        (self.root / '.dev-records/private-denylist.txt').write_text(self.marker + '\n')
        for name, email in [('Fixture Author', 'noreply@github.com'),
                            ('GitHub', 'noreply@github.com.evil.test')]:
            with self.subTest(name=name, email=email):
                self.git('config', 'user.name', name)
                self.git('config', 'user.email', email)
                self.git('commit', '--amend', '--author', 'Fixture Author <fixture@example.com>', '-m', 'fixture')
                self.assertTrue(any(f.get('category') == 'non-example-email' for f in scan_history(self.root)))

    def test_github_web_committer_exception_does_not_cover_authors(self):
        self.git('add', '-A')
        self.git('commit', '--author', 'GitHub <noreply@github.com>', '-m', 'fixture')
        self.assertTrue(any(f.get('category') == 'non-example-email' for f in scan_history(self.root)))

    def test_other_identity_emails_are_still_rejected(self):
        for email in ['fixture@invalid.example', '123+fixture@users.noreply.github.com.evil.test']:
            with self.subTest(email=email):
                self.git('config', 'user.email', email)
                self.git('add', '-A')
                self.git('commit', '--allow-empty', '-m', 'fixture change')
                self.assertTrue(any(f.get('category') == 'non-example-email' for f in scan_history(self.root)))

    def test_removed_development_records_remain_forbidden(self):
        p = self.root / 'docs/development/progress.md'
        p.parent.mkdir(parents=True)
        p.write_text('private work log\n')
        self.commit()
        p.unlink()
        self.commit()
        self.assertTrue(any(f.get('category') == 'private-record-in-history' for f in scan_history(self.root)))

    def test_ignored_denylist_cannot_be_committed_silently(self):
        self.git('add', '-f', '.dev-records/private-denylist.txt')
        self.git('commit', '-m', 'bad fixture')
        self.assertTrue(any(f.get('category') == 'private-record-in-history' for f in scan_history(self.root)))

    def test_denylist_also_covers_checker_sources(self):
        p = self.root / 'scripts/checks/leaked.txt'
        p.parent.mkdir(parents=True)
        p.write_text(self.marker)
        self.assertEqual([{'file': 'scripts/checks/leaked.txt', 'line': 1}], scan(self.root))


if __name__ == '__main__':
    unittest.main()
