#!/usr/bin/env python3
"""Reject legacy site/account bindings; print paths, lines and categories only."""
import json
import re
from privacy import ROOT, sources

TERMS = re.compile(r'cdcccm|Mengde|mengde')
# The historical identity column is intentionally retained. Its original schema,
# serialization hiding, and log redaction are protections, not service bindings.
COLUMN_PROTECTIONS = {
    'app/Models/User.php',
    'app/Support/Logging/RedactSensitiveContext.php',
    'app/Support/OperationalTextRedactor.php',
}
SCHEMA = {
    'database/migrations/0001_01_01_000000_create_users_table.php',
    'database/migrations/2026_06_27_000001_add_mengde_identity_to_users_table.php',
}


def scan(root=ROOT):
    findings = []
    for path, text in sources(root):
        if path.startswith('scripts/checks/'):
            continue
        for number, line in enumerate(text.splitlines(), 1):
            candidate = line
            if path in COLUMN_PROTECTIONS or path in SCHEMA:
                candidate = candidate.replace('mengde_user_id', '')
                if path == 'app/Support/OperationalTextRedactor.php':
                    candidate = candidate.replace('mengde[_-]?user[_-]?id', '')
            if TERMS.search(candidate):
                findings.append({'file': path, 'line': number, 'category': 'legacy-account-binding'})
    return findings


if __name__ == '__main__':
    findings = scan()
    print(json.dumps(findings, ensure_ascii=False, indent=2))
    raise SystemExit(bool(findings))
