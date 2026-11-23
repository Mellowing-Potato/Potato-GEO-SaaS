"""Source-level grants must survive binary distribution alongside module licenses."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


COLLECTOR = Path(__file__).resolve().parents[2] / 'scripts/runtime/collect-web-go-notices.py'


class CompiledGoNoticesTest(unittest.TestCase):
    def collect(self, files, fields=None):
        temporary = tempfile.TemporaryDirectory(prefix='yanji-notice-test-')
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        source = root / 'source'
        source.mkdir()
        for name, content in files.items():
            (source / name).write_bytes(content)
        fields = fields or {'GoFiles': list(files)}
        stream = root / 'packages.json'
        stream.write_text(json.dumps({'ImportPath': 'example.test/component', 'Dir': str(source), **fields}))
        text, index = root / 'notices.txt', root / 'sources.json'
        result = subprocess.run([sys.executable, str(COLLECTOR), str(stream), str(text), str(index)],
                                capture_output=True, text=True)
        return result, text, index

    def test_deep_long_grant_is_retained_continuously_without_size_cutoffs(self):
        grant = '/* Copyright (c) Example Authors\nPermission is hereby granted\n' + ('original grant line\n' * 1100) + 'All rights reserved.\n*/'
        raw = ('package demo\nvar padding = "' + ('x' * 18000) + '"\n' + grant + '\n').encode()
        result, text, index = self.collect({'deep.go': raw})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn(grant.encode(), text.read_bytes())
        source = json.loads(index.read_text())['sources']['example.test/component/deep.go']
        self.assertEqual(hashlib.sha256(raw).hexdigest(), source['sha256'])
        self.assertEqual([hashlib.sha256(grant.encode()).hexdigest()], source['notice_sha256'])

    def test_code_strings_are_excluded_and_the_actual_independent_grant_remains(self):
        real = '// Copyright Example Authors\n// Permission is hereby granted to use this software.\n// The above copyright notice shall be included in all copies.'
        raw = ('package demo\nvar fake = `/* Copyright Bogus Raw String */`\n'
               'var another = "// Copyright Bogus Quoted String"\n' + real + '\n').encode()
        result, text, index = self.collect({'independent.go': raw})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn(real.encode(), text.read_bytes())
        self.assertNotIn(b'Bogus', text.read_bytes())
        self.assertEqual(1, json.loads(index.read_text())['distinct_notices'])

    def test_cgo_headers_and_assembly_are_in_the_recorded_active_graph(self):
        files = {'bridge.go': b'package demo\n', 'bridge_cgo.go': b'// Copyright Example Cgo Authors\npackage demo\n',
                 'bridge.h': b'/* Copyright Example Native Authors */\n',
                 'bridge.s': b'# Copyright Example Assembly Authors\n# Licensed under MIT\n'}
        result, text, index = self.collect(files, {'GoFiles': ['bridge.go'], 'CgoFiles': ['bridge_cgo.go'],
                                                 'HFiles': ['bridge.h'], 'SFiles': ['bridge.s']})
        self.assertEqual(0, result.returncode, result.stderr)
        recorded = json.loads(index.read_text())
        self.assertEqual(4, recorded['source_files'])
        self.assertEqual(3, recorded['distinct_notices'])
        for label in ['Cgo Authors', 'Native Authors', 'Assembly Authors']:
            self.assertIn(label.encode(), text.read_bytes())

    def test_missing_active_source_stops_before_publishing_an_incomplete_notice(self):
        result, text, index = self.collect({}, {'GoFiles': ['missing.go']})
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Missing active source', result.stderr)
        self.assertFalse(text.exists())
        self.assertFalse(index.exists())

    def test_invalid_encoding_cannot_silently_change_an_author_attribution(self):
        result, text, index = self.collect({'invalid.go': b'// Copyright \xff Example Authors\n'})
        self.assertNotEqual(0, result.returncode)
        self.assertIn('UnicodeDecodeError', result.stderr)
        self.assertFalse(text.exists())
        self.assertFalse(index.exists())


if __name__ == '__main__':
    unittest.main()
