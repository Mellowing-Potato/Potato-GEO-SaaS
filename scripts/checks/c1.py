#!/usr/bin/env python3
"""Check the C1 billing-removal boundary; report paths, lines and categories only."""
import json
import re
from privacy import ROOT, sources

TERMS = re.compile(r'QingheCommercial|PointDebit|BalanceGate|GeoCommercial|checkout|topup|Subscription|Invoice')


def scan(root=ROOT):
    findings = []
    for path, text in sources(root):
        # Historical schema definitions and the new table-removal migration are
        # explicitly outside C1's runtime-source boundary (review appendix B).
        if path.startswith(('database/migrations/', 'scripts/checks/')):
            continue
        for number, line in enumerate(text.splitlines(), 1):
            for match in TERMS.finditer(line):
                # The user explicitly chose to retain this unused stylesheet in
                # C1. This exemption covers only its retired page's name.
                if path == 'public/geo-checkout.css' and match.group() == 'checkout':
                    continue
                findings.append({'file': path, 'line': number, 'category': 'billing-reference'})
                break
    return findings


if __name__ == '__main__':
    findings = scan()
    print(json.dumps(findings, ensure_ascii=False, indent=2))
    raise SystemExit(bool(findings))
