"""Keep native acceptance diagnostics useful without disclosing captured secrets."""
import json
from pathlib import Path
import runpy
import unittest


class PortableDesktopDiagnosticsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        source = Path(__file__).resolve().parents[2] / 'scripts/test-portable-desktop.py'
        cls.parse = staticmethod(runpy.run_path(str(source))['private_fixture_diagnostic'])

    def test_nested_diagnostics_keep_only_allowlisted_fields_and_actual_booleans(self):
        secret = 'sk-test-never-expose-private-details'
        record = {
            'fixture': 'portable-desktop', 'stage': 'desktop_launch', 'exception': 'RuntimeException',
            'code': 'launcher_rejected', 'message': secret, 'path': secret, 'token': secret,
            'application': {
                'stage': 'diagnostic_process', 'exit_code': 70, 'stdout_bytes': 0, 'stderr_bytes': 400,
                'detail': {
                    'stage': 'application_launch_file', 'code': 'ticket_file_changed',
                    'exception': 'RuntimeException', 'file': 'PortableDesktopAccess.php', 'line': 237,
                    'message': secret, 'token': secret, 'path': secret, 'dev': 123456,
                    'flags': {'fstat_ok': True, 'ino_equal': False, 'ticket_nonempty': False,
                              'root_private': 1, 'run_private': 'true', 'ticket_exists': None,
                              'path': secret, 'dev': 123456},
                },
            },
        }
        clean = self.parse(json.dumps(record).encode())
        self.assertEqual(clean['stage'], 'desktop_launch')
        detail = clean['application']['detail']
        self.assertEqual(detail['code'], 'ticket_file_changed')
        self.assertEqual(detail['file'], 'PortableDesktopAccess.php')
        self.assertEqual(detail['line'], 237)
        self.assertEqual(detail['flags'], {'fstat_ok': True, 'ino_equal': False, 'ticket_nonempty': False})
        self.assertNotIn(secret, json.dumps(clean))
        self.assertNotIn('123456', json.dumps(clean))
        for blocked in ('message', 'path', 'token', 'dev'):
            self.assertNotIn(blocked, clean)
            self.assertNotIn(blocked, detail)

    def test_untrusted_output_and_invalid_types_or_numeric_boundaries_are_not_echoed(self):
        secret = 'sk-test-untrusted-output'
        rejected = [secret.encode(), b'\xff', b'[]', b'x' * 16385,
                    json.dumps({'fixture': 'different', 'message': secret}).encode(),
                    json.dumps({'fixture': True, 'message': secret}).encode()]
        for value in rejected:
            with self.subTest(output_type=type(value).__name__, length=len(value)):
                self.assertIsNone(self.parse(value))
        record = {'fixture': 'portable-desktop', 'stage': 'desktop_launch', 'code': secret,
                  'file': secret, 'exception': secret, 'line': 100000001, 'stdout_bytes': -1,
                  'stderr_bytes': True, 'exit_code': -256}
        self.assertEqual(self.parse(json.dumps(record).encode()), {'stage': 'desktop_launch'})
        boundaries = {'fixture': 'portable-desktop', 'line': 100000000, 'stdout_bytes': 0,
                      'stderr_bytes': 10, 'exit_code': -255}
        self.assertEqual(self.parse(json.dumps(boundaries).encode()),
                         {'line': 100000000, 'stdout_bytes': 0, 'stderr_bytes': 10, 'exit_code': -255})
        record['line'] = 1.5
        self.assertNotIn('line', self.parse(json.dumps(record).encode()))


if __name__ == '__main__':
    unittest.main()
