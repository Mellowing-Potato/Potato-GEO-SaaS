"""Offline release publication checks: tiny archives, a fake gh, and no network or installer."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('portable_release_publish', ROOT / 'scripts/runtime/publish-portable-release.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)
BOOT_SPEC = importlib.util.spec_from_file_location('release_bootstrap', ROOT / 'scripts/runtime/make-release-bootstrap.py')
bootstrap = importlib.util.module_from_spec(BOOT_SPEC)
BOOT_SPEC.loader.exec_module(bootstrap)
REPO = 'example/repository'
TAG = 'v0.1.1'


# This executable accepts only the fixed fake repository, records every call, and never uses a network.
FAKE_GH = r'''import hashlib, json, os, pathlib, shutil, sys
state_file = pathlib.Path(os.environ['YANJI_FAKE_RELEASE_STATE'])
state = json.loads(state_file.read_text())
args = sys.argv[1:]
state['calls'].append(args)
def save(): state_file.write_text(json.dumps(state))
def fail(code=1):
    save()
    raise SystemExit(code)
if args[0] == 'api':
    endpoint = args[1]
    if not endpoint.startswith('repos/example/repository/'): fail(91)
    if endpoint.endswith('/git/ref/tags/v0.1.1'):
        print(json.dumps({'object': {'type':'commit','sha':state['tag_commit']}}))
    elif endpoint.endswith('/releases/tags/v0.1.1'):
        if not state['exists']:
            print('gh: Not Found (HTTP 404)', file=sys.stderr)
            fail()
        metadata = {'tag_name':'v0.1.1','draft':False,'prerelease':False,'assets':[]}
        for path in sorted(pathlib.Path(state['remote']).iterdir()):
            data = path.read_bytes()
            sha = hashlib.sha256(data).hexdigest()
            item = {'name':path.name,'state':'uploaded','size':len(data), 'digest':'sha256:'+sha,
                'browser_download_url':'https://github.com/example/repository/releases/download/v0.1.1/'+path.name}
            if state.get('corrupt_remote_digest') == path.name: item['digest'] = 'sha256:'+'0'*64
            if state.get('missing_digest') == path.name: item.pop('digest')
            metadata['assets'].append(item)
        print(json.dumps(metadata))
    else: fail(92)
elif args[:2] == ['release','view']:
    if '--repo' not in args or args[args.index('--repo')+1] != 'example/repository': fail(93)
    if not state['exists']: fail()
elif args[:2] == ['release','download']:
    if '--repo' not in args or args[args.index('--repo')+1] != 'example/repository': fail(93)
    destination = pathlib.Path(args[args.index('--dir')+1])
    names = [args[i+1] for i, arg in enumerate(args) if arg == '--pattern']
    for path in pathlib.Path(state['remote']).iterdir():
        if not names or path.name in names:
            target = destination/path.name
            shutil.copyfile(path,target)
            if state.get('corrupt_download') == path.name:
                target.write_bytes(target.read_bytes()+b'changed')
elif args[:2] == ['release','create']:
    if '--repo' not in args or '--verify-tag' not in args: fail(94)
    state['exists'] = True
elif args[:2] == ['release','upload']:
    if '--repo' not in args or args[args.index('--repo')+1] != 'example/repository' or '--clobber' not in args: fail(95)
    path = pathlib.Path(args[3])
    attempts = state.setdefault('attempts',{})
    attempts[path.name] = attempts.get(path.name,0)+1
    if state.get('fail_asset') == path.name and (state.get('fail_always') or attempts[path.name] == 1): fail()
    shutil.copyfile(path,pathlib.Path(state['remote'])/path.name)
    if state.get('change_tag_after_upload'): state['tag_commit'] = '0'*40
else: fail(96)
save()
'''


class PortableReleasePublishTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='yanji-release-offline-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name)
        self.source = self.base / 'source'
        self.source.mkdir()
        (self.source / 'scripts').mkdir()
        (self.source / 'VERSION').write_bytes(b'0.1.1\n')
        for name in release.INSTALLERS:
            (self.source / 'scripts' / name).write_bytes(b'# inert installer fixture, never executed\n')
        for args in (['init', '-q'], ['add', '.'],
                     ['-c', 'user.name=Release Fixture', '-c', 'user.email=release@example.com',
                      'commit', '-qm', 'inert release fixture']):
            subprocess.run(['git', '-C', str(self.source), *args], check=True, capture_output=True)
        self.commit = subprocess.check_output(['git', '-C', str(self.source), 'rev-parse', 'HEAD'], text=True).strip()
        self.materials = self.base / 'release'
        self.materials.mkdir()
        self.remote = self.base / 'remote'
        self.remote.mkdir()
        self.backup = self.base / 'backup'
        self.state_file = self.base / 'state.json'
        self.state = {'exists':False,'tag_commit':self.commit,'remote':str(self.remote),'calls':[]}
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        gh = self.bin / 'gh'
        gh.write_text('#!' + sys.executable + '\n' + FAKE_GH, encoding='utf-8')
        gh.chmod(0o700)
        self.enterContext(patch.dict(os.environ, {
            'PATH':str(self.bin)+os.pathsep+os.environ.get('PATH',''),
            'YANJI_FAKE_RELEASE_STATE':str(self.state_file)}))
        self.make_materials()

    def make_archive(self, platform, *, commit=None, version='0.1.1'):
        name = release.PACKAGES[platform]
        receipt = {'commit':commit or self.commit,'platform':platform}
        for kind in ('cli','web'):
            kit = f'fixture-{kind}-{platform}-source.tar.gz'
            if not (self.materials / kit).exists():
                (self.materials / kit).write_bytes(('inert corresponding source '+kit+'\n').encode())
            receipt[kind+'_source_kit'] = kit
            receipt[kind+'_source_kit_sha256'] = release.digest(self.materials / kit)
        files = {'release.json':json.dumps(receipt).encode(), 'app/VERSION':(version+'\n').encode()}
        if name.endswith('.zip'):
            with zipfile.ZipFile(self.materials / name,'w') as archive:
                for path,data in files.items(): archive.writestr(path,data)
        else:
            with tarfile.open(self.materials / name,'w:gz') as archive:
                for path,data in files.items():
                    info = tarfile.TarInfo(path)
                    info.size = len(data)
                    archive.addfile(info,io.BytesIO(data))

    def make_materials(self):
        for platform in release.PACKAGES:
            self.make_archive(platform)
        for name in release.INSTALLERS:
            shutil.copyfile(self.source / 'scripts' / name,self.materials / name)
        self.repin()

    def repin(self):
        entries = sorted(path for path in self.materials.iterdir() if path.name not in release.BOOTSTRAPS|{'SHA256SUMS'})
        (self.materials / 'SHA256SUMS').write_text(''.join(release.digest(path)+'  '+path.name+'\n' for path in entries))
        bootstrap.generate('https://github.com/'+REPO+'/releases/download/'+TAG,
                           self.materials / 'SHA256SUMS',self.materials)

    def verify(self):
        return release.verify_directory(self.materials,self.source,self.commit,TAG,REPO)

    def run_publish(self):
        self.state_file.write_text(json.dumps(self.state))
        self.sleeps = []
        try:
            return release.publish(self.materials,self.source,self.commit,TAG,REPO,self.backup,sleeper=self.sleeps.append)
        finally:
            self.state = json.loads(self.state_file.read_text())

    def existing_release(self):
        self.state['exists'] = True
        # Different old bytes prove rollback materials are not silently the new assets.
        for path in self.materials.iterdir():
            (self.remote / path.name).write_bytes(b'previous release fixture '+path.name.encode()+b'\n')

    def uploads(self):
        return [Path(call[3]).name for call in self.state['calls'] if call[:2] == ['release','upload']]

    def test_complete_four_platform_source_and_version_are_required(self):
        self.assertEqual(len(self.verify()),17)
        self.make_archive('windows-x86_64',commit='a'*40)
        self.repin()
        with self.assertRaisesRegex(release.ReleaseError,'package_source_commit_differs:windows'):
            self.verify()

    def test_missing_platform_or_source_kit_cannot_be_published(self):
        for name in (release.PACKAGES['linux-x86_64'],'fixture-web-macos-arm64-source.tar.gz'):
            path = self.materials / name
            old = path.read_bytes()
            path.unlink()
            with self.assertRaisesRegex(release.ReleaseError,'seventeen'):
                self.verify()
            path.write_bytes(old)

    def test_wrong_package_version_and_uncommitted_installer_are_rejected(self):
        self.make_archive('macos-arm64',version='0.0.0')
        self.repin()
        with self.assertRaisesRegex(release.ReleaseError,'package_version_differs'):
            self.verify()
        self.make_archive('macos-arm64')
        (self.materials / 'install-local.sh').write_bytes(b'# changed fixture\n')
        self.repin()
        with self.assertRaisesRegex(release.ReleaseError,'installer_differs_from_source'):
            self.verify()

    def test_manifest_duplicate_unsafe_or_digest_changes_are_rejected(self):
        path = self.materials / 'SHA256SUMS'
        original = path.read_bytes()
        for data,code in [(original+original.splitlines(keepends=True)[0],'duplicate_manifest'),
                          (b'0'*64+b'  ../unsafe\n','invalid_manifest'),
                          (original.replace(original[:64],b'0'*64,1),'local_asset_digest')]:
            with self.subTest(code=code):
                path.write_bytes(data)
                with self.assertRaisesRegex(release.ReleaseError,code): self.verify()
        path.write_bytes(original)

    def test_bootstrap_fixed_tag_manifest_and_installer_pins_are_exact(self):
        path = self.materials / 'get-yanji.ps1'
        path.write_bytes(path.read_bytes().replace(b'/v0.1.1',b'/v0.0.0'))
        with self.assertRaisesRegex(release.ReleaseError,'bootstrap_pin_differs'):
            self.verify()

    def test_new_release_creates_then_uploads_all_assets_without_repository_context(self):
        # Current directory is unrelated to the fixture repository; every release call names --repo.
        report = self.run_publish()
        self.assertTrue(report['remote_asset_sha256_verified'])
        self.assertTrue(report['remote_small_asset_bytes_verified'])
        self.assertFalse(report['gui_verified'])
        self.assertFalse(report['previous_release_backed_up'])
        self.assertEqual(len(self.uploads()),17)
        for call in self.state['calls']:
            if call[0] == 'release':
                self.assertIn('--repo',call)
                self.assertEqual(call[call.index('--repo')+1],REPO)
        self.assertTrue(any(call[:2] == ['release','create'] for call in self.state['calls']))

    def test_existing_release_is_backed_up_before_any_clobber_and_trust_roots_are_last(self):
        self.existing_release()
        old = {path.name:path.read_bytes() for path in self.remote.iterdir()}
        report = self.run_publish()
        self.assertTrue(report['previous_release_backed_up'])
        self.assertTrue((self.backup / 'BACKUP-VERIFIED').is_file())
        self.assertEqual({path.name:path.read_bytes() for path in (self.backup/'assets').iterdir()},old)
        calls = self.state['calls']
        backup_download = next(i for i,c in enumerate(calls) if c[:2] == ['release','download'])
        first_upload = next(i for i,c in enumerate(calls) if c[:2] == ['release','upload'])
        self.assertLess(backup_download,first_upload)
        self.assertEqual(set(self.uploads()[:12]),set(self.verify())-release.SMALL_FILES)
        self.assertEqual(self.uploads()[12:14],sorted(release.INSTALLERS))
        self.assertEqual(self.uploads()[-3:],['SHA256SUMS',*sorted(release.BOOTSTRAPS)])
        self.assertFalse(any(c[:2] == ['release','create'] for c in calls))

    def test_transient_upload_is_retried_and_persistent_failure_stops_after_four(self):
        first = sorted(set(self.verify())-release.SMALL_FILES)[0]
        self.state['fail_asset'] = first
        self.run_publish()
        self.assertEqual(self.state['attempts'][first],2)
        self.assertEqual(self.sleeps,[5])
        shutil.rmtree(self.backup)
        self.state.update(fail_always=True,attempts={},calls=[])
        with self.assertRaisesRegex(release.ReleaseError,'retries_exhausted'):
            self.run_publish()
        self.assertEqual(self.state['attempts'][first],4)
        self.assertEqual(self.uploads(),[first]*4)
        self.assertEqual(self.sleeps,[5,10,15])

    def test_bad_backup_or_missing_digest_blocks_before_asset_mutation(self):
        self.existing_release()
        for flag in ('corrupt_download','missing_digest'):
            with self.subTest(flag=flag):
                self.state.update(calls=[],**{flag:'SHA256SUMS'})
                with self.assertRaisesRegex(release.ReleaseError,'backup_digest|sha256_unverified'):
                    self.run_publish()
                self.assertEqual(self.uploads(),[])
                self.state.pop(flag)
                shutil.rmtree(self.backup)

    def test_remote_tag_drift_blocks_before_mutation_and_after_upload(self):
        self.state['tag_commit'] = 'b'*40
        with self.assertRaisesRegex(release.ReleaseError,'remote_tag_differs'):
            self.run_publish()
        self.assertEqual(self.uploads(),[])
        self.state.update(tag_commit=self.commit,change_tag_after_upload=True,calls=[])
        with self.assertRaisesRegex(release.ReleaseError,'remote_tag_differs'):
            self.run_publish()

    def test_uploaded_missing_digest_and_small_bytes_mismatch_are_not_success(self):
        for flag,code in [('missing_digest','sha256_unverified'),('corrupt_download','small_asset_bytes_differ')]:
            with self.subTest(flag=flag):
                self.state.update(exists=False,calls=[],**{flag:'get-yanji.sh'})
                with self.assertRaisesRegex(release.ReleaseError,code): self.run_publish()
                self.state.pop(flag)
                shutil.rmtree(self.backup)
                for path in self.remote.iterdir(): path.unlink()

    def test_release_workflow_requires_every_native_platform_and_public_reader_entry(self):
        workflow = (ROOT / '.github/workflows/release.yml').read_text()
        self.assertIn('cancel-in-progress: false',workflow)
        self.assertNotIn("needs.portable-unix.result == 'skipped'",workflow)
        self.assertNotIn("if: vars.YANJI_UNIX_RUNTIME_MANIFEST_URL",workflow)
        self.assertIn('test -n "$MANIFEST_URL"',workflow)
        self.assertIn('test -n "$MANIFEST_SHA256"',workflow)
        self.assertIn('native-desktop-entry.php D:\\yanji-build\\render-check\\app\\portable',workflow)
        self.assertIn('native-desktop-entry.php /tmp/yanji-render-check/app/portable',workflow)
        self.assertIn('scripts/runtime/publish-portable-release.py',workflow)
        self.assertIn('path: /tmp/yanji-previous-release',workflow)
        self.assertIn('published-windows-onboarding:',workflow)
        windows = workflow.split('  published-windows-onboarding:',1)[1]
        self.assertIn('needs: publish-release',windows)
        self.assertIn('scenario: [fresh, interrupted]',windows)
        self.assertIn('scripts/test-published-windows-onboarding.py --version',windows)
        self.assertIn('SOURCE_COMMIT: ${{ github.sha }}',windows)


if __name__ == '__main__':
    unittest.main()
