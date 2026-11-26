"""Read-only support for CI acceptance after the standard installer dispatches a browser.

This module neither opens a browser nor changes an authorization. The subsequent onboarding
snapshot and every cookie/workspace assertion remain mandatory. It does not diagnose a prior run.
"""
from contextlib import contextmanager
import ctypes
import errno
import json
import math
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time


LIFETIME_SECONDS = 120  # PortableDesktopAccess::LIFETIME_SECONDS.
MAX_WAIT_SECONDS = 125
EXPIRY_GRACE_SECONDS = 1
STABLE_SECONDS = 1
POLL_SECONDS = 0.25
MAX_TICKET_BYTES = 4096
STAGES = frozenset({'acceptance', 'preflight', 'interrupted_fixture', 'published_pipeline',
    'installed_release', 'services', 'initial_authorization_settle', 'onboarding',
    'acceptance_marker', 'workspace_authorization', 'cleanup'})
SUPPORT_CODES = frozenset({'initial_authorization_ci_required', 'initial_authorization_unavailable',
    'initial_authorization_invalid', 'initial_authorization_replaced', 'initial_authorization_timed_out',
    'initial_authorization_missing', 'initial_authorization_path_untrusted', 'initial_authorization_not_private',
    'initial_authorization_too_large', 'initial_authorization_read_denied', 'initial_authorization_read_locked',
    'initial_authorization_read_error'})
ACCEPTANCE_CODES = frozenset({
    'invalid_release_tag', 'invalid_release_source', 'fresh_github_hosted_macos_required',
    'fresh_github_hosted_windows_required', 'fixed_release_source_required',
    'default_runner_home_required', 'default_runner_local_app_data_required',
    'windows_known_folder_lookup_failed', 'default_yanji_directory_already_exists',
    'default_runner_directory_is_linked', 'default_port_already_in_use',
    'interrupted_fixture_template_invalid', 'published_bootstrap_download_failed',
    'published_reader_installation_failed', 'published_reader_installation_timed_out',
    'installed_metadata_invalid', 'default_instance_launcher_missing',
    'installed_release_pointer_invalid', 'installed_release_outside_default_program',
    'installed_version_differs_from_release_tag', 'installed_platform_is_not_macos_arm64',
    'installed_platform_is_not_windows_x86_64', 'installed_source_differs_from_release_run',
    'installed_instance_did_not_use_default_port', 'installed_services_status_failed',
    'installed_services_not_ready', 'installed_service_roles_not_healthy',
    'cleanup_launcher_is_linked', 'installed_instance_cleanup_failed',
    'invalid_acceptance_scenario', 'desktop_workspace_identity_invalid'})
ASSERTION_CODES = {
    'The no-terminal management query failed or emitted unexpected output': 'management_query_failed',
    'The management query did not return one JSON object': 'management_json_invalid',
    'The management query did not return an object': 'management_object_invalid',
    'Agent onboarding was checked before the managed instance was ready': 'onboarding_instance_not_ready',
    'Agent onboarding requires healthy Web, worker and scheduler services': 'onboarding_services_unhealthy',
    'Reading agent onboarding changed configuration, entries or authorization files': 'onboarding_files_changed',
    'Agent onboarding does not match the schema version 1 contract': 'onboarding_schema_invalid',
    'Agent onboarding identifies a different product or package version': 'onboarding_product_version_invalid',
    'Agent onboarding did not describe the initialized desktop instance': 'onboarding_desktop_mode_invalid',
    'Agent onboarding treated service readiness as human browser confirmation': 'onboarding_confirmation_invalid',
    'Agent onboarding omitted the verified local entry description': 'onboarding_entry_description_invalid',
    'Agent onboarding entry is not named for the current instance': 'onboarding_entry_name_invalid',
    'Agent onboarding did not provide its canonical entry file': 'onboarding_entry_missing',
    'Agent onboarding entry is outside or belongs to a different instance': 'onboarding_entry_binding_invalid',
    'Restoring a backup reused the original instance entry': 'onboarding_restored_entry_reused',
    'Agent onboarding omitted the actionable instance command': 'onboarding_action_invalid',
    'Agent onboarding suggested a command for a different instance': 'onboarding_command_binding_invalid',
    'Agent onboarding exposed an authorization field': 'onboarding_authorization_field_exposed',
    'Agent onboarding exposed authorization material': 'onboarding_authorization_material_exposed',
    'Agent onboarding disclosed the instance encryption key': 'onboarding_encryption_key_exposed',
    'An anonymous desktop request reached private project data': 'anonymous_workspace_access_allowed',
    'A desktop instance unexpectedly requires an account login form': 'desktop_account_form_required',
    'The desktop opener received an authorization instead of a private file URI': 'desktop_opener_not_private_file',
    'The desktop launch page is outside this instance private runtime directory': 'desktop_launch_file_binding_invalid',
    'The desktop launch page is readable by another system user': 'desktop_launch_file_not_private',
    'The private desktop authorization targets a different instance': 'desktop_authorization_binding_invalid',
    'The native desktop authorization does not contain a valid nonce': 'desktop_authorization_nonce_invalid',
    'The native desktop nonce could not establish a browser session': 'desktop_cookie_exchange_failed',
    'Desktop authorization did not return a session object': 'desktop_session_object_invalid',
    'Desktop authorization did not issue an API credential': 'desktop_api_credential_missing',
    'Desktop authorization did not issue a protected HttpOnly session cookie': 'desktop_cookie_not_protected',
    'The browser session cannot access its authenticated workspace': 'desktop_workspace_cookie_rejected',
    'The authenticated workspace API did not return an account object': 'desktop_workspace_account_invalid',
    'The authenticated API identity differs from the authorized desktop session': 'desktop_workspace_identity_mismatch',
    'The authenticated desktop session cannot access the workspace API': 'desktop_workspace_api_rejected',
    'A desktop API token bypassed its browser-cookie binding': 'desktop_unbound_api_token_accepted',
    'A consumed desktop nonce was accepted twice': 'desktop_nonce_replay_accepted',
    'Desktop authorization did not return an account and workspace object': 'desktop_identity_objects_invalid',
    'Desktop authorization returned a missing or invalid account/workspace identity': 'desktop_identity_missing',
    'Desktop authorization returned an account assigned to a different workspace': 'desktop_identity_binding_invalid',
}


class PublicEntrySupportError(Exception):
    def __init__(self, code, identity=None, stamp=None):
        self.code = code if code in SUPPORT_CODES else 'initial_authorization_invalid'
        self.stage = 'initial_authorization_settle'
        # Only used internally to reject replacement while a nonblocking lock is busy.
        # safe_failure_diagnostic deliberately never serializes this value.
        self.identity = identity
        self.stamp = stamp
        super().__init__(self.code)


def safe_failure_diagnostic(error, stage='acceptance'):
    """Return only fixed public codes; never interpolate a message, command, path or credential."""
    stage = stage if isinstance(stage, str) and stage in STAGES else 'acceptance'
    code = getattr(error, 'code', None)
    if isinstance(code, str) and code in SUPPORT_CODES | ACCEPTANCE_CODES:
        return {'stage': stage, 'code': code}
    if isinstance(error, AssertionError):
        message = error.args[0] if len(error.args) == 1 and type(error.args[0]) is str else None
        code = ASSERTION_CODES.get(message, 'helper_assertion_failed')
    elif isinstance(error, subprocess.TimeoutExpired):
        code = 'acceptance_operation_timed_out'
    elif isinstance(error, json.JSONDecodeError):
        code = 'acceptance_json_invalid'
    elif isinstance(error, OSError):
        code = 'acceptance_filesystem_or_process_failed'
    else:
        code = 'unexpected_acceptance_failure'
    return {'stage': stage, 'code': code}


def _linked(info):
    return stat.S_ISLNK(info.st_mode) or bool(getattr(info, 'st_file_attributes', 0) & 0x400)


def _identity(info):
    return info.st_dev, info.st_ino


def _read_error(error, identity=None, *, locking=False, stamp=None):
    native = getattr(error, 'winerror', None)
    if native in (32, 33) or (native is None and locking and error.errno in (errno.EAGAIN, errno.EWOULDBLOCK)):
        code = 'initial_authorization_read_locked'
    elif native in (2, 3) or (native is None and error.errno == errno.ENOENT):
        code = 'initial_authorization_missing'
    elif native == 5 or (native is None and error.errno in (errno.EACCES, errno.EPERM)):
        code = 'initial_authorization_read_denied'
    elif native is None and error.errno in (errno.ELOOP, errno.ENOTDIR):
        code = 'initial_authorization_path_untrusted'
    else:
        code = 'initial_authorization_read_error'
    return PublicEntrySupportError(code, identity, stamp)


class _OVERLAPPED(ctypes.Structure):
    # Fixed-width DWORDs and pointer-sized ULONG_PTR/HANDLE match the Windows ABI.
    _fields_ = [('Internal', ctypes.c_size_t), ('InternalHigh', ctypes.c_size_t),
                ('Offset', ctypes.c_uint32), ('OffsetHigh', ctypes.c_uint32), ('hEvent', ctypes.c_void_p)]


def _windows_lock_api():
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.LockFileEx.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_uint32,
                                 ctypes.c_uint32, ctypes.c_uint32, ctypes.POINTER(_OVERLAPPED)]
    kernel.LockFileEx.restype = ctypes.c_int32
    kernel.UnlockFileEx.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_uint32,
                                   ctypes.c_uint32, ctypes.POINTER(_OVERLAPPED)]
    kernel.UnlockFileEx.restype = ctypes.c_int32
    return kernel


def _windows_handle(descriptor):
    import msvcrt
    return msvcrt.get_osfhandle(descriptor)


def _windows_error():
    error = OSError(errno.EIO, 'authorization file operation failed')
    error.winerror = ctypes.get_last_error()
    return error


@contextmanager
def _shared_lock(descriptor):
    """Cooperate with PHP flock without waiting inside a native call or modifying bytes.

    PHP's Windows implementation uses LockFileEx over this exact offset/range:
    https://github.com/php/php-src/blob/PHP-8.5/ext/standard/flock_compat.c
    https://learn.microsoft.com/windows/win32/api/fileapi/nf-fileapi-lockfileex
    """
    if sys.platform == 'win32':
        kernel, handle, offset = _windows_lock_api(), _windows_handle(descriptor), _OVERLAPPED()
        # FAIL_IMMEDIATELY only: absence of EXCLUSIVE_LOCK requests a shared read lock.
        if not kernel.LockFileEx(handle, 1, 0, 0xFFFFFFFF, 0xFFFFFFFF, ctypes.byref(offset)):
            raise _windows_error()
        try:
            yield
        finally:
            if not kernel.UnlockFileEx(handle, 0, 0xFFFFFFFF, 0xFFFFFFFF, ctypes.byref(offset)):
                raise _windows_error()
    else:
        import fcntl
        fcntl.flock(descriptor, fcntl.LOCK_SH | fcntl.LOCK_NB)
        try:
            yield
        finally:
            fcntl.flock(descriptor, fcntl.LOCK_UN)


def _assert_private_regular(info):
    if _linked(info) or not stat.S_ISREG(info.st_mode):
        raise PublicEntrySupportError('initial_authorization_path_untrusted')
    if info.st_size > MAX_TICKET_BYTES:
        raise PublicEntrySupportError('initial_authorization_too_large')
    if os.name != 'nt' and (info.st_mode & 0o077 or info.st_uid != os.geteuid()):
        raise PublicEntrySupportError('initial_authorization_not_private')


def _read_ticket(instance):
    """Read bounded regular bytes without following links; the caller never receives the contents."""
    path = Path(instance) / 'run' / 'desktop-ticket.json'
    identity = stamp = None
    locking = False
    try:
        for directory in (Path(instance), path.parent):
            info = directory.lstat()
            if _linked(info) or not stat.S_ISDIR(info.st_mode):
                raise PublicEntrySupportError('initial_authorization_path_untrusted')
        before = path.lstat()
        _assert_private_regular(before)
        identity = _identity(before)
        stamp = before.st_mtime_ns
        descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0))
        try:
            opened = os.fstat(descriptor)
            if _identity(opened) != identity:
                raise PublicEntrySupportError('initial_authorization_replaced')
            _assert_private_regular(opened)
            locking = True
            with _shared_lock(descriptor):
                locking = False
                locked = os.fstat(descriptor)
                current = path.lstat()
                if _identity(current) != identity or _identity(locked) != identity:
                    raise PublicEntrySupportError('initial_authorization_replaced')
                _assert_private_regular(current)
                _assert_private_regular(locked)
                data = os.read(descriptor, MAX_TICKET_BYTES + 1)
                current = path.lstat()
                after = os.fstat(descriptor)
                if _identity(current) != identity or _identity(after) != identity:
                    raise PublicEntrySupportError('initial_authorization_replaced')
                _assert_private_regular(current)
                _assert_private_regular(after)
                if len(data) > MAX_TICKET_BYTES:
                    raise PublicEntrySupportError('initial_authorization_too_large')
                if data:
                    _record(data, time.time())
                return identity, data, after.st_mtime_ns
        finally:
            os.close(descriptor)
    except PublicEntrySupportError:
        raise
    except OSError as error:
        raise _read_error(error, identity, locking=locking, stamp=stamp) from None


def _read_when_unlocked(instance, start, expected_identity=None):
    """Retry only an identified lock conflict, within the same overall 125-second budget."""
    identity = expected_identity
    locked_stamp = None
    while True:
        try:
            result = _read_ticket(instance)
            if identity is not None and result[0] != identity:
                raise PublicEntrySupportError('initial_authorization_replaced')
            if result[1] and locked_stamp is not None and result[2] != locked_stamp:
                raise PublicEntrySupportError('initial_authorization_replaced')
            return result
        except PublicEntrySupportError as error:
            if error.code != 'initial_authorization_read_locked' or error.identity is None:
                raise
            if identity is not None and error.identity != identity:
                raise PublicEntrySupportError('initial_authorization_replaced') from None
            identity = error.identity
            # Keep the earliest stamp: consumption may empty it, but a rewritten nonempty
            # record cannot silently become our initial authorization after waiting for a lock.
            if locked_stamp is None:
                locked_stamp = error.stamp
            remaining = MAX_WAIT_SECONDS - (time.monotonic() - start)
            if remaining <= 0:
                raise PublicEntrySupportError('initial_authorization_read_locked') from None
            time.sleep(min(POLL_SECONDS, remaining))


def _record(data, now):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError()
            result[key] = value
        return result
    try:
        record = json.loads(data, object_pairs_hook=unique)
        if not isinstance(record, dict) or set(record) != {'fingerprint', 'user_id', 'tenant_id', 'binding', 'expires_at'}:
            raise ValueError()
        for key in ('fingerprint', 'binding'):
            if type(record[key]) is not str or not re.fullmatch(r'[a-f0-9]{64}', record[key]):
                raise ValueError()
        for key in ('user_id', 'tenant_id', 'expires_at'):
            if type(record[key]) is not int or record[key] <= 0:
                raise ValueError()
        if record['expires_at'] > math.floor(now) + LIFETIME_SECONDS:
            raise ValueError()
        return record['expires_at']
    except (ValueError, TypeError, UnicodeDecodeError):
        raise PublicEntrySupportError('initial_authorization_invalid') from None


def wait_for_initial_authorization_settle(instance):
    """Wait for the *same* initial authorization to be consumed or expire, then take strict snapshots.

    Call only after the unchanged public installer command and healthy service checks. No terminal,
    native opener, renewal, HTTP authorization, deletion or file write is performed here.
    """
    expected = {'GITHUB_ACTIONS': 'true', 'RUNNER_ENVIRONMENT': 'github-hosted',
                'GITHUB_REPOSITORY': 'Mellowing-Potato/Potato-GEO-SaaS'}
    if any(os.environ.get(key) != value for key, value in expected.items()) \
            or os.environ.get('RUNNER_OS') not in {'macOS', 'Windows'}:
        raise PublicEntrySupportError('initial_authorization_ci_required')
    start = time.monotonic()
    identity, original, stamp = _read_when_unlocked(instance, start)
    expires = None if original == b'' else _record(original, time.time())
    empty_since = time.monotonic() if original == b'' else None
    empty_stamp = stamp if original == b'' else None
    expired_since = None
    while True:
        now = time.monotonic()
        if now - start > MAX_WAIT_SECONDS:
            raise PublicEntrySupportError('initial_authorization_timed_out')
        if empty_since is not None:
            if now - empty_since >= STABLE_SECONDS:
                return {'state': 'consumed', 'waited_seconds': math.ceil(now - start)}
        elif time.time() >= expires + EXPIRY_GRACE_SECONDS:
            expired_since = now if expired_since is None else expired_since
            if now - expired_since >= STABLE_SECONDS:
                return {'state': 'expired', 'waited_seconds': math.ceil(now - start)}
        else:
            expired_since = None
        remaining = MAX_WAIT_SECONDS - (now - start)
        if remaining <= 0:
            raise PublicEntrySupportError('initial_authorization_timed_out')
        time.sleep(min(POLL_SECONDS, remaining))
        current_identity, data, current_stamp = _read_when_unlocked(instance, start, identity)
        if current_identity != identity:
            raise PublicEntrySupportError('initial_authorization_replaced')
        if data == b'':
            if empty_since is None:
                empty_since, empty_stamp = time.monotonic(), current_stamp
            elif current_stamp != empty_stamp:
                raise PublicEntrySupportError('initial_authorization_replaced')
        elif empty_since is not None or data != original or current_stamp != stamp:
            raise PublicEntrySupportError('initial_authorization_replaced')
