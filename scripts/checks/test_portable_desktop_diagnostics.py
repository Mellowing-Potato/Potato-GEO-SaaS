"""Keep native acceptance diagnostics useful without disclosing captured secrets."""
import json
import io
from pathlib import Path
import runpy
import tempfile
import unittest


class PortableDesktopDiagnosticsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        source = Path(__file__).resolve().parents[2] / 'scripts/test-portable-desktop.py'
        module = runpy.run_path(str(source))
        cls.parse = staticmethod(module['private_fixture_diagnostic'])
        cls.write_marker = staticmethod(module['write_acceptance_marker'])

    def test_windows_text_translation_cannot_satisfy_the_strict_fixture_marker(self):
        buffer = io.BytesIO()
        text = io.TextIOWrapper(buffer, encoding='utf-8', newline='\r\n')
        text.write('desktop-fixture\n')
        text.flush()
        translated = buffer.getvalue()
        self.assertEqual(translated, b'desktop-fixture\r\n')
        self.assertNotEqual(translated, b'desktop-fixture\n')
        fixture = Path(__file__).resolve().parents[2] / 'tests/fixtures/portable-desktop.php'
        self.assertIn('file_get_contents($root.\'/.portable-acceptance\') !== "desktop-fixture\\n"',
                      fixture.read_text(encoding='utf-8'))

    def test_scenario_marker_writer_uses_exact_binary_lf_without_text_translation(self):
        class BinaryOnlyPath:
            value = None

            def write_bytes(self, value):
                self.value = value
                return len(value)

            def write_text(self, *_args, **_kwargs):
                raise AssertionError('Acceptance markers must not pass through platform text translation')

        marker = BinaryOnlyPath()
        self.write_marker(marker)
        self.assertIs(type(marker.value), bytes)
        self.assertEqual(marker.value, b'desktop-fixture\n')
        with tempfile.TemporaryDirectory(prefix='yanji-marker-bytes-') as directory:
            actual = Path(directory) / '.portable-acceptance'
            self.write_marker(actual)
            self.assertEqual(actual.read_bytes(), b'desktop-fixture\n')

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
