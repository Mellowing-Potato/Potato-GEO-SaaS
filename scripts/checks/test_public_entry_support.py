"""Fake-clock public-entry observation tests; no installer, browser, services or network."""
import ctypes
import errno
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
from unittest.mock import Mock, patch


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

    def test_initial_identified_lock_conflict_retries_then_observes_stable_consumption(self):
        calls = 0
        def read(_instance):
            nonlocal calls
            calls += 1
            if calls == 1:
                raise support.PublicEntrySupportError('initial_authorization_read_locked',(1,2),1)
            return (1,2),b'',2
        self.reader.side_effect = read
        self.assertEqual(support.wait_for_initial_authorization_settle(FakePath('example-instance')),
                         {'state':'consumed','waited_seconds':2})
        self.assertEqual(self.clock.elapsed,1.25)

    def test_initial_busy_reads_share_the_overall_budget_and_reject_replacement(self):
        self.reader.side_effect = support.PublicEntrySupportError('initial_authorization_read_locked',(1,2),1)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'read_locked'):
            support.wait_for_initial_authorization_settle(FakePath('example-instance'))
        self.assertEqual(self.clock.elapsed,125)
        for changed in [((1,3),b'',2),((1,2),ticket(fingerprint='c'*64),2)]:
            with self.subTest(changed_identity=changed[0],nonempty=bool(changed[1])):
                self.clock.elapsed,self.clock.wall = 0,1000
                self.reader.side_effect = [support.PublicEntrySupportError('initial_authorization_read_locked',(1,2),1),changed]
                with self.assertRaisesRegex(support.PublicEntrySupportError,'replaced'):
                    support.wait_for_initial_authorization_settle(FakePath('example-instance'))

    def test_missing_access_denied_untrusted_or_unidentified_busy_reads_are_not_retried(self):
        for code in ('initial_authorization_missing','initial_authorization_read_denied',
                     'initial_authorization_path_untrusted','initial_authorization_not_private',
                     'initial_authorization_read_error','initial_authorization_read_locked'):
            with self.subTest(code=code):
                self.reader.reset_mock()
                self.reader.side_effect = support.PublicEntrySupportError(code)
                with self.assertRaises(support.PublicEntrySupportError) as caught:
                    support.wait_for_initial_authorization_settle(FakePath('example-instance'))
                self.assertEqual(caught.exception.code,code)
                self.assertEqual(self.reader.call_count,1)
                self.assertEqual(self.clock.elapsed,0)

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
        with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_missing$'):
            support._read_ticket(self.instance)
        self.path.mkdir()
        with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_path_untrusted$'):
            support._read_ticket(self.instance)
        self.path.rmdir()
        self.path.write_bytes(b'x'*(support.MAX_TICKET_BYTES+1))
        self.path.chmod(0o600)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_too_large$'):
            support._read_ticket(self.instance)
        self.path.unlink()
        target = self.instance / 'other-file'
        target.write_bytes(ticket())
        self.path.symlink_to(target)
        with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_path_untrusted$'):
            support._read_ticket(self.instance)

    def test_private_permissions_and_access_denied_remain_fail_closed_with_distinct_codes(self):
        fake = SimpleNamespace(st_mode=stat.S_IFREG|0o644,st_size=10,st_uid=1000)
        with patch.object(support.os,'name','posix'),patch.object(support.os,'geteuid',return_value=1000,create=True):
            with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_not_private$'):
                support._assert_private_regular(fake)
            fake.st_mode,fake.st_uid = stat.S_IFREG|0o600,1001
            with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_not_private$'):
                support._assert_private_regular(fake)
        with patch.object(support.os,'open',side_effect=PermissionError(errno.EACCES,'sk-test-private-path')):
            with self.assertRaises(support.PublicEntrySupportError) as caught:
                support._read_ticket(self.instance)
        self.assertEqual(caught.exception.code,'initial_authorization_read_denied')

    def test_read_occurs_only_under_shared_lock_and_invalid_schema_still_fails(self):
        from contextlib import contextmanager
        locked = False
        actual_read = support.os.read
        @contextmanager
        def lock(_descriptor):
            nonlocal locked
            locked = True
            try:
                yield
            finally:
                locked = False
        def read(descriptor,count):
            self.assertTrue(locked)
            return actual_read(descriptor,count)
        with patch.object(support,'_shared_lock',lock),patch.object(support.os,'read',side_effect=read):
            self.assertEqual(support._read_ticket(self.instance)[1],ticket())
            self.path.write_bytes(b'{"unexpected":"sk-test-private-value"}')
            with self.assertRaisesRegex(support.PublicEntrySupportError,'initial_authorization_invalid$'):
                support._read_ticket(self.instance)
        self.assertFalse(locked)

    def test_exclusive_writer_conflicts_with_reader_then_shared_read_preserves_bytes(self):
        descriptor = os.open(self.path,os.O_RDWR)
        if support.sys.platform == 'win32':
            kernel,handle,offset = support._windows_lock_api(),support._windows_handle(descriptor),support._OVERLAPPED()
            self.assertTrue(kernel.LockFileEx(handle,3,0,0xFFFFFFFF,0xFFFFFFFF,ctypes.byref(offset)))
            unlock = lambda:kernel.UnlockFileEx(handle,0,0xFFFFFFFF,0xFFFFFFFF,ctypes.byref(offset))
        else:
            import fcntl
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
            unlock = lambda:fcntl.flock(descriptor,fcntl.LOCK_UN)
        try:
            with self.assertRaises(support.PublicEntrySupportError) as caught:
                support._read_ticket(self.instance)
            self.assertEqual(caught.exception.code,'initial_authorization_read_locked')
            self.assertEqual(caught.exception.identity,(os.fstat(descriptor).st_dev,os.fstat(descriptor).st_ino))
        finally:
            unlock()
            os.close(descriptor)
        self.assertEqual(support._read_ticket(self.instance)[1],ticket())
        self.assertEqual(self.path.read_bytes(),ticket())

    def test_os_errors_distinguish_known_lock_conflicts_from_denials_and_other_failures(self):
        cases = [(5,errno.EACCES,'read_denied'),(32,errno.EACCES,'read_locked'),
                 (33,errno.EACCES,'read_locked'),(2,errno.ENOENT,'missing'),(87,errno.EINVAL,'read_error')]
        for native,number,code in cases:
            error = OSError(number,'sk-test-never-output')
            error.winerror = native
            self.assertEqual(support._read_error(error).code,'initial_authorization_'+code)
        denied = PermissionError(errno.EACCES,'sk-test-never-output')
        self.assertEqual(support._read_error(denied,locking=True).code,'initial_authorization_read_denied')
        busy = BlockingIOError(errno.EAGAIN,'sk-test-never-output')
        self.assertEqual(support._read_error(busy,locking=True).code,'initial_authorization_read_locked')
        self.assertEqual(support._read_error(busy,locking=False).code,'initial_authorization_read_error')

    def test_windows_shared_lock_uses_fixed_width_abi_full_php_range_and_nonblocking_flag(self):
        kernel = SimpleNamespace(LockFileEx=Mock(return_value=1),UnlockFileEx=Mock(return_value=1))
        with patch.object(support.ctypes,'WinDLL',return_value=kernel,create=True):
            self.assertIs(support._windows_lock_api(),kernel)
        self.assertEqual(kernel.LockFileEx.argtypes,[ctypes.c_void_p,ctypes.c_uint32,ctypes.c_uint32,
            ctypes.c_uint32,ctypes.c_uint32,ctypes.POINTER(support._OVERLAPPED)])
        self.assertEqual(kernel.LockFileEx.restype,ctypes.c_int32)
        self.assertEqual(ctypes.sizeof(support._OVERLAPPED),32 if ctypes.sizeof(ctypes.c_void_p)==8 else 20)
        with patch.object(support.sys,'platform','win32'),patch.object(support,'_windows_lock_api',return_value=kernel), \
                patch.object(support,'_windows_handle',return_value=0x100000001):
            with support._shared_lock(17):
                self.assertEqual(kernel.UnlockFileEx.call_count,0)
        args = kernel.LockFileEx.call_args.args
        self.assertEqual(args[:5],(0x100000001,1,0,0xFFFFFFFF,0xFFFFFFFF))
        self.assertEqual(args[5]._obj.Offset,0)
        self.assertEqual(args[5]._obj.OffsetHigh,0)
        self.assertIsNone(args[5]._obj.hEvent)
        self.assertEqual(kernel.UnlockFileEx.call_args.args[:4],(0x100000001,0,0xFFFFFFFF,0xFFFFFFFF))
        self.assertIs(kernel.UnlockFileEx.call_args.args[4]._obj,args[5]._obj)

    def test_windows_lock_failure_never_reads_or_unlocks_an_unacquired_lock(self):
        for native,code in ((32,'read_locked'),(33,'read_locked'),(5,'read_denied'),(87,'read_error')):
            kernel = SimpleNamespace(LockFileEx=Mock(return_value=0),UnlockFileEx=Mock(return_value=1))
            with self.subTest(native=native),patch.object(support.sys,'platform','win32'), \
                    patch.object(support,'_windows_lock_api',return_value=kernel), \
                    patch.object(support,'_windows_handle',return_value=31), \
                    patch.object(support.ctypes,'get_last_error',return_value=native,create=True), \
                    patch.object(support.os,'read') as read:
                with self.assertRaises(support.PublicEntrySupportError) as caught:
                    support._read_ticket(self.instance)
                self.assertEqual(caught.exception.code,'initial_authorization_'+code)
                self.assertIsNotNone(caught.exception.identity)
                read.assert_not_called()
                kernel.UnlockFileEx.assert_not_called()

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
