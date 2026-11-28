"""Guard C1 billing-removal scan boundaries after removing retired checkout CSS."""
from pathlib import Path
import tempfile
import unittest

import c1


class BillingBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='c1-boundary-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def write(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding='utf-8')

    def test_clean_runtime_source_passes(self):
        self.write('public/geo-base.css', '.md-app { color: var(--md-text); }\n')
        self.assertEqual([], c1.scan(self.root))

    def test_retired_checkout_stylesheet_no_longer_has_exemption(self):
        self.write('public/geo-checkout.css', '.checkout { max-width: 1000px; }\n')
        self.assertEqual(
            [{'file': 'public/geo-checkout.css', 'line': 1, 'category': 'billing-reference'}],
            c1.scan(self.root),
        )

    def test_historical_migrations_and_check_scripts_stay_outside_runtime_boundary(self):
        self.write('database/migrations/2026_01_01_000000_legacy.php', 'Subscription::class;\n')
        self.write('scripts/checks/sample.py', 'checkout\n')
        self.assertEqual([], c1.scan(self.root))


if __name__ == '__main__':
    unittest.main()
