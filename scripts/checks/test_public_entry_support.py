"""Fake-clock public-entry observation tests; no installer, browser, services or network."""
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import runpy
import stat
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('public_entry_support', ROOT / 'scripts/public-entry-support.py')
support = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(support)


def ticket(expires=1120, **changes):
    record = {'fingerprint':'a'*64, 'binding':'b'*64, 'user_id':1, 'tenant_id':1, 'expires_at':expires}
    record.update(changes)
    return json.dumps(record).encode()


class FakeClock:
    def __init__(self):
        self.elapsed = 0
        self.wall = 1000
        self.callback = lambda:None
        self.advance_wall = True

    def monotonic(self):
        return self.elapsed

    def time(self):
        return self.wall

    def sleep(self, seconds):
        if seconds <= 0 or seconds > 1:
            raise AssertionError('Fake wait must be positive and bounded')
        self.elapsed += seconds
        if self.advance_wall:
            self.wall += seconds
        self.callback()


class FakePath:
    def __init__(self, value):
        self.path = PurePosixPath(str(value))

    def __truediv__(self, value):
        return FakePath(self.path / value)

    def __str__(self):
        return str(self.path)

    def __eq__(self, other):
        return isinstance(other, FakePath) and self.path == other.path

    @property
    def name(self):
        return self.path.name

    @property
    def parent(self):
        return FakePath(self.path.parent)

    def resolve(self):
        return self

    def is_symlink(self):
        return False

    def is_file(self):
        return True

    def read_text(self, **_kwargs):
        if self.name != 'VERSION':
            raise AssertionError('Unexpected fake read')
        return '0.1.1\n'

    def read_bytes(self):
        if self.name != '.env':
            raise AssertionError('Unexpected fake read')
        return b'APP_KEY=sk-test-fake-encryption-value\n'


class PublicEntrySupportTest(unittest.TestCase):
    def setUp(self):
        self.enterContext(patch.dict(os.environ, {'GITHUB_ACTIONS':'true', 'RUNNER_ENVIRONMENT':'github-hosted',
            'GITHUB_REPOSITORY':'Mellowing-Potato/Potato-GEO-SaaS', 'RUNNER_OS':'macOS'}))
        self.clock = FakeClock()
        self.enterContext(patch.object(support, 'time', self.clock))
        self.state = {'identity':(1,2), 'data':ticket(), 'stamp':1}
        self.reader = self.enterContext(patch.object(support, '_read_ticket', side_effect=lambda _instance:
            (self.state['identity'], self.state['data'], self.state['stamp'])))

    def consume_at(self, elapsed):
        def consume():
            if self.clock.elapsed >= elapsed and self.state['data']:
                self.state.update(data=b'',stamp=2)
        self.clock.callback = consume

    def test_pending_initial_authorization_is_observed_until_stably_consumed_without_writes(self):
        self.consume_at(0.5)
        result = support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.assertEqual(result, {'state':'consumed','waited_seconds':2})
        self.assertEqual(self.clock.elapsed,1.5)
        self.assertEqual(self.state['data'],b'')
        self.assertGreaterEqual(self.reader.call_count,3)

    def test_unused_ticket_waits_its_real_expiry_grace_and_stability_within_125_seconds(self):
        original = dict(self.state)
        result = support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.assertEqual(result, {'state':'expired','waited_seconds':122})
        self.assertEqual(self.state,original)
        self.assertGreaterEqual(self.clock.wall,1122)
        self.assertLessEqual(self.clock.elapsed,125)

    def test_initially_empty_or_expired_file_is_stable_before_returning(self):
        for data,expected in ((b'','consumed'),(ticket(expires=999),'expired')):
            with self.subTest(state=expected):
                self.clock.elapsed,self.clock.wall = 0,1000
                self.state.update(data=data,stamp=1)
                result = support.wait_for_initial_authorization_settle(FakePath('example-instance'))
                self.assertEqual(result,{'state':expected,'waited_seconds':1})

    def test_clock_stall_cannot_extend_the_monotonic_budget(self):
        self.clock.advance_wall = False
        with self.assertRaisesRegex(support.PublicEntrySupportError,'timed_out'):
            support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.assertEqual(self.clock.elapsed,125)

    def test_invalid_duplicate_oversized_expiry_or_non_integer_fields_are_never_settled(self):
        invalid = [b'not JSON', b'{}', b'[]', b' ', ticket(expires=1121), ticket(expires=True),
                   ticket(expires='1120'), ticket(expires=-1), ticket(user_id=True), ticket(tenant_id=0),
                   ticket(binding='sk-test-invalid-binding'), ticket(fingerprint='z'*64),
                   ticket(extra='sk-test-never-output'), ticket()+b' ',
                   ticket().replace(b'"user_id": 1',b'"user_id": 1, "user_id": 1')]
        # Whitespace surrounding valid JSON does not change the production record's meaning.
        invalid.remove(ticket()+b' ')
        for data in invalid:
            with self.subTest(data_type=type(data).__name__,length=len(data)):
                self.state['data'] = data
                with self.assertRaisesRegex(support.PublicEntrySupportError,'invalid'):
                    support.wait_for_initial_authorization_settle(FakePath('example-instance'))
                self.assertEqual(self.clock.elapsed,0)

    def test_inode_replacement_record_replacement_and_empty_file_rewrite_are_rejected(self):
        cases = [{'identity':(1,3),'data':b''}, {'data':ticket(fingerprint='c'*64),'stamp':2},
                 {'data':ticket(),'stamp':2}]
        for changed in cases:
            with self.subTest(changed_fields=sorted(changed)):
                self.clock.elapsed,self.clock.wall = 0,1000
                self.state.update(identity=(1,2),data=ticket(),stamp=1)
                self.clock.callback = lambda:self.state.update(changed)
                with self.assertRaisesRegex(support.PublicEntrySupportError,'replaced'):
                    support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.clock.elapsed,self.clock.wall = 0,1000
        self.state.update(identity=(1,2),data=b'',stamp=1)
        self.clock.callback = lambda:self.state.update(stamp=2)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'replaced'):
            support.wait_for_initial_authorization_settle(FakePath('example-instance'))

    def test_consumed_file_cannot_become_a_new_pending_ticket(self):
        self.state['data'] = b''
        self.clock.callback = lambda:self.state.update(data=ticket(fingerprint='c'*64),stamp=2)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'replaced'):
            support.wait_for_initial_authorization_settle(FakePath('example-instance'))

    def test_non_ci_self_hosted_wrong_repository_or_platform_refuses_before_file_read(self):
        for key,value in [('GITHUB_ACTIONS','false'),('RUNNER_ENVIRONMENT','self-hosted'),
                          ('GITHUB_REPOSITORY','example/repository'),('RUNNER_OS','Linux')]:
            with self.subTest(key=key),patch.dict(os.environ,{key:value}):
                with self.assertRaisesRegex(support.PublicEntrySupportError,'ci_required'):
                    support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.reader.assert_not_called()

    def strict_onboarding(self, *, mutate=None):
        helper = runpy.run_path(str(ROOT / 'scripts/test-portable-desktop.py'))['onboarding_and_check']
        namespace = helper.__globals__
        instance = FakePath('/example-instance')
        extension = '.cmd' if namespace['IS_WIN'] else ('.command' if os.uname().sysname=='Darwin' else '.desktop')
        name = '言及工作台-8000-aaaaaaaa'
        canonical = str(instance / (name+extension))
        status = {'ready':True,'writes_enabled':True,'services':{role:{'healthy':True}
            for role in ('web','worker','scheduler')}}
        report = {'schema_version':1,'product':'yanji','version':'0.1.1','mode':'desktop','initialized':True,
            'services_ready':True,'state':'awaiting_browser_confirmation','browser_confirmation_required':True,
            'entry':{'name':name,'file':canonical,'shortcuts':[],'warnings':[]},
            'next_action':{'action':'open','command':[str(instance / ('yanji.cmd' if namespace['IS_WIN'] else 'yanji')),'open'],
                           'human_entry':canonical}}
        def read(_launcher,command):
            if mutate:
                mutate(command)
            result = status if command=='status' else report
            return result,json.dumps(result).encode()
        def snapshot(_instance):
            return {'run/desktop-ticket.json':self.state['data'],'.env':self.state.get('configuration',b'original')}
        with patch.dict(namespace,{'Path':FakePath,'ROOT':FakePath('/example-source'),
                                   'onboarding_files':snapshot,'read_management_json':read}):
            return helper(instance,8000)

    def test_old_unsettled_browser_consume_false_failure_is_prevented_before_strict_snapshot(self):
        def browser_consume(_command):
            if self.state['data']:
                self.state.update(data=b'',stamp=2)
        with self.assertRaisesRegex(AssertionError,'Reading agent onboarding changed'):
            self.strict_onboarding(mutate=browser_consume)
        self.state.update(data=ticket(),stamp=1)
        self.consume_at(0.25)
        support.wait_for_initial_authorization_settle(FakePath('/example-instance'))
        self.assertIsInstance(self.strict_onboarding(mutate=browser_consume),FakePath)

    def test_settling_does_not_allow_onboarding_to_modify_authorization_or_configuration(self):
        self.state.update(data=b'',stamp=2)
        support.wait_for_initial_authorization_settle(FakePath('/example-instance'))
        for kind in ('data','configuration'):
            self.state.update(data=b'',configuration=b'original')
            def illegal_write(command):
                if command=='onboarding':
                    self.state[kind] = ticket(fingerprint='c'*64) if kind=='data' else b'changed'
            with self.subTest(field=kind),self.assertRaisesRegex(AssertionError,'Reading agent onboarding changed'):
                self.strict_onboarding(mutate=illegal_write)

    def test_safe_diagnostics_map_only_exact_allowlisted_assertions_codes_and_stages(self):
        for message,code in support.ASSERTION_CODES.items():
            self.assertEqual(support.safe_failure_diagnostic(AssertionError(message),'onboarding'),
                             {'stage':'onboarding','code':code})
        secret = 'sk-test-private-value /example/private/path'
        for error in (AssertionError(secret),AssertionError('Reading agent onboarding changed configuration, entries or authorization files '+secret),
                      RuntimeError(secret),OSError(secret),subprocess.TimeoutExpired(secret,1,output=secret),
                      json.JSONDecodeError(secret,secret,1),support.PublicEntrySupportError(secret)):
            value = support.safe_failure_diagnostic(error,secret)
            self.assertEqual(value['stage'],'acceptance')
            self.assertNotIn(secret,json.dumps(value))
            self.assertNotIn('message',value)
        for code in support.ACCEPTANCE_CODES|support.SUPPORT_CODES:
            error = RuntimeError(secret)
            error.code = code
            self.assertEqual(support.safe_failure_diagnostic(error,'cleanup'),{'stage':'cleanup','code':code})


class PublicEntryTicketFileTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='yanji-ticket-reader-fixture-')
        self.addCleanup(temporary.cleanup)
        self.instance = Path(temporary.name)
        (self.instance / 'run').mkdir(mode=0o700)
        self.path = self.instance / 'run/desktop-ticket.json'
        self.path.write_bytes(ticket())
        self.path.chmod(0o600)

    def test_regular_bounded_file_is_opened_read_only_and_unchanged(self):
        before = self.path.stat()
        original_open = support.os.open
        with patch.object(support.os,'open',wraps=original_open) as opened:
            identity,data,_stamp = support._read_ticket(self.instance)
        self.assertEqual(identity,(before.st_dev,before.st_ino))
        self.assertEqual(data,ticket())
        self.assertEqual(opened.call_args.args[1] & (os.O_WRONLY|os.O_RDWR|os.O_TRUNC|os.O_CREAT),0)
        self.assertEqual(self.path.stat().st_mtime_ns,before.st_mtime_ns)
        self.assertEqual(self.path.read_bytes(),ticket())

    def test_missing_directory_link_and_oversized_file_are_refused(self):
        self.path.unlink()
        with self.assertRaisesRegex(support.PublicEntrySupportError,'unavailable'):
            support._read_ticket(self.instance)
        self.path.mkdir()
        with self.assertRaisesRegex(support.PublicEntrySupportError,'unavailable'):
            support._read_ticket(self.instance)
        self.path.rmdir()
        self.path.write_bytes(b'x'*(support.MAX_TICKET_BYTES+1))
        self.path.chmod(0o600)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'unavailable'):
            support._read_ticket(self.instance)
        self.path.unlink()
        target = self.instance / 'other-file'
        target.write_bytes(ticket())
        self.path.symlink_to(target)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'unavailable'):
            support._read_ticket(self.instance)

    def test_reparse_point_and_descriptor_identity_mismatch_are_refused(self):
        fake = SimpleNamespace(st_mode=stat.S_IFREG|0o600,st_file_attributes=0x400)
        self.assertTrue(support._linked(fake))
        original_stat = os.fstat
        def changed(descriptor):
            actual = original_stat(descriptor)
            return SimpleNamespace(st_mode=actual.st_mode,st_dev=actual.st_dev,st_ino=actual.st_ino+1)
        with patch.object(support.os,'fstat',side_effect=changed):
            with self.assertRaisesRegex(support.PublicEntrySupportError,'replaced'):
                support._read_ticket(self.instance)

    def test_production_ticket_field_and_expiry_contract_remains_matched(self):
        source = (ROOT / 'app/Support/PortableDesktopAccess.php').read_text()
        self.assertIn('private const LIFETIME_SECONDS = 120;',source)
        for key in ('fingerprint','user_id','tenant_id','binding','expires_at'):
            self.assertIn("'"+key+"' =>",source)
        self.assertIn("self::writeRecord($file, '');",source)
        self.assertEqual(support.LIFETIME_SECONDS,120)
        self.assertEqual(support.MAX_WAIT_SECONDS,125)


if __name__ == '__main__':
    unittest.main()
