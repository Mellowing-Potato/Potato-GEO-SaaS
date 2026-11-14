#!/usr/bin/env python3
"""Check the generic-model boundary without printing matched values."""
import json
import re
from privacy import ROOT, sources

TERMS = re.compile(r'qinghe|Qinghe|potato|X-Points-Unit-Version|gateway_context|geo-text|geo-embed|geo-vision', re.IGNORECASE)


def scan(root=ROOT):
    findings = []
    for path, text in sources(root):
        # Review appendix B, C3: historical migrations remain unchanged;
        # schema changes and removal of obsolete tables use new migrations.
        if path.startswith(('database/migrations/', 'scripts/checks/')):
            continue
        for number, line in enumerate(text.splitlines(), 1):
            if TERMS.search(line):
                findings.append({'file': path, 'line': number, 'category': 'private-model-protocol'})
    return findings


if __name__ == '__main__':
    findings = scan()
    print(json.dumps(findings, ensure_ascii=False, indent=2))
    raise SystemExit(bool(findings))
