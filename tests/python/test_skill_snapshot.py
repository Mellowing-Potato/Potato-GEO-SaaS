"""A changed upstream archive must never become a usable release snapshot."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('fetch_skills', Path(__file__).resolve().parents[2] / 'scripts/fetch-skills.py')
fetch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fetch)


class SkillSnapshotTest(unittest.TestCase):
    def archive(self, changed=False, extra=False):
        data = {'skills/demo/SKILL.md': b'approved', 'registry/skills.json': b'{}', 'LICENSE': b'MIT fixture'}
        commit = '1' * 40
        lock = {'upstream_commit': commit, 'approved_skill_ids': ['demo'],
                'files': {'demo/SKILL.md': hashlib.sha256(data['skills/demo/SKILL.md']).hexdigest()},
                'registry_sha256': hashlib.sha256(data['registry/skills.json']).hexdigest(),
                'license_sha256': hashlib.sha256(data['LICENSE']).hexdigest()}
        if changed:
            data['skills/demo/SKILL.md'] = b'changed upstream instruction'
        if extra:
            data['skills/demo/extra.md'] = b'unapproved'
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w:gz') as tar:
            for name, value in data.items():
                member = tarfile.TarInfo('yao-geo-skills-' + commit + '/' + name)
                member.size = len(value)
                tar.addfile(member, io.BytesIO(value))
        return lock, buffer.getvalue()

    def run_fetch(self, changed=False, extra=False):
        lock, archive = self.archive(changed, extra)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'lock.json').write_text(json.dumps(lock))
            with patch.object(fetch.urllib.request, 'urlopen', return_value=io.BytesIO(archive)):
                fetch.install(root/'lock.json', root/'output')
            self.assertEqual(b'approved', (root/'output/skills/demo/SKILL.md').read_bytes())
            self.assertTrue((root/'output/LICENSE').is_file())
            self.assertEqual(lock['upstream_commit'], (root/'output/.upstream-commit').read_text().strip())

    def test_verified_snapshot_keeps_license_and_commit(self):
        self.run_fetch()

    def test_changed_content_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            self.run_fetch(changed=True)

    def test_additional_unlocked_input_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'file list differs'):
            self.run_fetch(extra=True)


if __name__ == '__main__':
    unittest.main()
