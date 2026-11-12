#!/usr/bin/python3
"""Bounded private archive verification; Production alone owns findings and gates."""
from dataclasses import dataclass, fields
from datetime import datetime, timezone
from contextlib import contextmanager
import argparse
import fcntl
import hashlib
import json
import ipaddress
import math
import os
from pathlib import Path
import pwd
import re
import selectors
import signal
import sqlite3
import stat
import subprocess
import sys
import time
import uuid

SERVICE = 'kiwi-retention-archive-health.service'
GROUP = '/system.slice/' + SERVICE
REQUEST_LIMIT = 16384
RESPONSE_LIMIT = 262144
GENERATION = re.compile(r'kiwi_retention_archive_[0-9]{4}(?:_part_(?:[2-9]|[1-9][0-9]+))?\.sqlite\Z')
RESULT_FIELDS = ('schema_version', 'attempt_id', 'generation', 'received_bytes', 'sha256',
                 'transfer_complete', 'check', 'check_completed', 'outcome')
PROTECTION_FIELDS = {'pending', 'corruption_write_blocked', 'corruption_incident_open', 'replacement_transition_blocked'}
HANDOFF = '.start-request.json'
START_COMMAND = ['/usr/bin/sudo', '-n', '--', '/usr/bin/env', '-i', 'PATH=/usr/bin:/bin',
                 '/usr/bin/systemctl', '--no-block', '--job-mode=fail', 'start', SERVICE]


class HealthError(Exception):
    def __init__(self, message, phase='start', reason='health_run_failed', uncertain=False):
        super().__init__(message)
        self.phase, self.reason, self.uncertain = phase, reason, uncertain


@dataclass(frozen=True)
class Limits:
    source_bytes_per_second: int = 4194304
    copy_seconds: float = 2700
    copy_verification_seconds: float = 5400
    check_seconds: float = 900
    result_seconds: float = 300
    connect_seconds: int = 15
    control_seconds: float = 30
    cpu_quota_percent: int = 50
    cpu_weight: int = 10
    read_bytes_per_second: int = 16777216
    write_bytes_per_second: int = 8388608
    buffer_bytes: int = 1073741824
    reserve_bytes: int = 20000000000
    max_archive_bytes: int = 0

    def validate(self):
        durations = {'copy_seconds', 'copy_verification_seconds', 'check_seconds', 'result_seconds', 'control_seconds'}
        for field in fields(self):
            value = getattr(self, field.name)
            if field.name in durations:
                valid = type(value) in (int, float) and math.isfinite(value) and value > 0
            else:
                valid = type(value) is int and (value >= 0 if field.name == 'max_archive_bytes' else value > 0)
            if not valid:
                raise HealthError('A configured limit is invalid.')
        if self.copy_seconds > self.copy_verification_seconds or self.connect_seconds > self.control_seconds \
                or self.cpu_weight > 10000:
            raise HealthError('Configured phase or resource limits are inconsistent.')


def clean_environment():
    return {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}


@dataclass(frozen=True)
class Configuration:
    limits: Limits
    ssh_host: str
    ssh_user: str
    ssh_port: int
    identity_file: Path
    known_hosts_file: Path

    def ssh_command(self, action):
        if action not in ('status', 'export', 'result', 'fail'):
            raise HealthError('SSH action is invalid.')
        options = ['BatchMode=yes', 'IdentitiesOnly=yes', 'IdentityAgent=none', 'StrictHostKeyChecking=yes',
                   'GlobalKnownHostsFile=/dev/null', 'UserKnownHostsFile=' + str(self.known_hosts_file),
                   'ConnectTimeout=' + str(self.limits.connect_seconds), 'ConnectionAttempts=1',
                   'Compression=no', 'RequestTTY=no', 'ClearAllForwardings=yes', 'ForwardAgent=no',
                   'ControlMaster=no', 'ControlPath=none', 'PermitLocalCommand=no']
        command = ['/usr/bin/ssh', '-F', '/dev/null']
        for option in options:
            command.extend(['-o', option])
        command.extend(['-i', str(self.identity_file), '-p', str(self.ssh_port), '-l', self.ssh_user,
                        self.ssh_host, action])
        return command, clean_environment()


def trusted_file(path, owner, private=False):
    info = path.lstat()
    if not path.is_absolute() or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != owner \
            or info.st_mode & (0o077 if private else 0o027):
        raise HealthError('Configuration or credential ownership and permissions are invalid.')


def load_configuration(path=Path('/etc/kiwi-archive-health.conf')):
    path = Path(path)
    trusted_file(path, 0)
    with os.fdopen(open_private(path, os.O_RDONLY), 'rb') as stream:
        value = decode_object(stream.read(REQUEST_LIMIT + 1), REQUEST_LIMIT)
    limit_names = {field.name for field in fields(Limits)}
    names = {'ssh_host', 'ssh_user', 'ssh_port', 'identity_file', 'known_hosts_file'}
    if set(value) != limit_names | names:
        raise HealthError('Configuration fields are missing or unknown.')
    limits = Limits(**{name: value[name] for name in limit_names})
    limits.validate()
    host, user, port = value['ssh_host'], value['ssh_user'], value['ssh_port']
    if not isinstance(host, str) or len(host) > 253 or not isinstance(user, str) \
            or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_-]{0,31}', user) \
            or type(port) is not int or not 1 <= port <= 65535:
        raise HealthError('SSH destination configuration is invalid.')
    try:
        ipaddress.ip_address(host)
    except ValueError:
        if not host or not all(re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label) for label in host.split('.')):
            raise HealthError('SSH destination configuration is invalid.') from None
    paths = []
    for name, owner, private in [('identity_file', os.geteuid(), True), ('known_hosts_file', 0, False)]:
        if not isinstance(value[name], str) or len(value[name]) > 4096 or any(ord(char) < 32 for char in value[name]):
            raise HealthError('SSH credential path is invalid.')
        credential = Path(value[name])
        trusted_file(credential, owner, private)
        paths.append(credential)
    return Configuration(limits, host, user, port, *paths)


def json_bytes(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')


def decode_object(raw, limit):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('Duplicate key')
            result[key] = value
        return result
    if len(raw) > limit:
        raise HealthError('Protocol message exceeds its limit.')
    try:
        result = json.loads(raw.decode('utf-8'), object_pairs_hook=unique,
                            parse_constant=lambda value: (_ for _ in ()).throw(ValueError('Nonfinite value')))
    except (ValueError, UnicodeError, RecursionError):
        raise HealthError('Protocol message is invalid.') from None
    if not isinstance(result, dict):
        raise HealthError('Protocol object is missing.')
    return result


def fingerprint(request):
    return hashlib.sha256(json_bytes({key: request[key] for key in RESULT_FIELDS})).hexdigest()


def valid_time(value):
    if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z', value):
        return False
    try:
        datetime.fromisoformat(value.replace('Z', '+00:00'))
        return True
    except ValueError:
        return False


def file_identity(info):
    return [info.st_dev, info.st_ino, info.st_mode, info.st_size, info.st_mtime_ns, info.st_ctime_ns]


def open_private(path, flags, mode=0o600):
    return os.open(path, flags | os.O_NOFOLLOW | os.O_CLOEXEC, mode)


def private_descriptor(path, flags):
    descriptor = open_private(path, flags | os.O_NONBLOCK)
    info = os.fstat(descriptor)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_nlink != 1:
        os.close(descriptor)
        raise HealthError('Private control file ownership or permissions are invalid.')
    return descriptor


@contextmanager
def private_lock(path, wait_seconds=0):
    descriptor = private_descriptor(path, os.O_RDWR | os.O_CREAT)
    try:
        deadline = time.monotonic() + wait_seconds
        while True:
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise HealthError('Another local archive operation is active.') from None
                time.sleep(.02)
        yield descriptor
    finally:
        os.close(descriptor)


def requester_identity(pid):
    fields = (Path('/proc') / str(pid) / 'stat').read_text().rsplit(') ', 1)[1].split()
    if fields[0] == 'Z':
        raise HealthError('Start requester is no longer active.')
    return {'pid': pid, 'start_ticks': fields[19],
            'boot_id': Path('/proc/sys/kernel/random/boot_id').read_text().strip()}


def read_handoff(data):
    with os.fdopen(private_descriptor(data / HANDOFF, os.O_RDONLY), 'rb') as stream:
        record = decode_object(stream.read(REQUEST_LIMIT + 1), REQUEST_LIMIT)
    if set(record) != {'schema_version', 'attempt_id', 'start_kind', 'pid', 'start_ticks', 'boot_id', 'issued', 'state'} \
            or type(record['schema_version']) is not int or record['schema_version'] != 1 \
            or not isinstance(record['attempt_id'], str) or not re.fullmatch(r'[a-f0-9]{32}', record['attempt_id']) \
            or record['start_kind'] not in ('manual', 'scheduled') or record['state'] not in ('pending', 'claimed') \
            or type(record['pid']) is not int or record['pid'] <= 0 \
            or not isinstance(record['start_ticks'], str) or not record['start_ticks'].isdecimal() \
            or not isinstance(record['boot_id'], str) or not re.fullmatch(r'[a-f0-9-]{36}', record['boot_id']) \
            or type(record['issued']) not in (int, float) or not math.isfinite(record['issued']):
        raise HealthError('The previous start handoff needs inspection.')
    return record


def write_handoff(data, record):
    temporary = data / '.start-request.new'
    with os.fdopen(private_descriptor(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL), 'wb') as stream:
        stream.write(json_bytes(record) + b'\n')
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(data / HANDOFF)


def start_run(runner, start_kind):
    """Both public starts dispatch to, or claim a request inside, the one real service."""
    attempt = uuid.uuid4().hex
    controlled = False
    try:
        if start_kind not in ('manual', 'scheduled'):
            raise HealthError('Start kind is invalid.')
        runtime, limits = runner.runtime, runner.limits
        limits.validate()
        controlled = (runtime.proc / 'self/cgroup').read_text().strip() == '0::' + GROUP
        runtime.private_directory(runtime.data)
        runtime.private_directory(runtime.logs)
        if controlled:
            # The runner claims the handoff and run lock in one dispatch critical section.
            return runner.run(start_kind, attempt)
        with private_lock(runtime.data / '.dispatch.lock'):
            # Never queue over an existing controller, copy or unclaimed/uncertain request.
            with private_lock(runtime.data / '.run.lock'):
                if any(item.name not in ('.dispatch.lock', '.run.lock') for item in runtime.data.iterdir()):
                    raise HealthError('A previous private run still needs inspection.')
                record = dict(requester_identity(os.getpid()), schema_version=1, attempt_id=attempt,
                              start_kind=start_kind, issued=time.monotonic(), state='pending')
                write_handoff(runtime.data, record)
        # The caller never invokes SSH. The delegated argv itself clears all inherited environment.
        deadline = time.monotonic() + limits.control_seconds
        started = subprocess.run(START_COMMAND, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                 stderr=subprocess.DEVNULL, env=clean_environment(), timeout=limits.control_seconds,
                                 check=False, close_fds=True)
        if started.returncode != 0:
            raise HealthError('The controlled service could not be started.')
        while time.monotonic() < deadline:
            with private_lock(runtime.data / '.dispatch.lock', max(0, deadline - time.monotonic())):
                claimed = read_handoff(runtime.data)
                if claimed == dict(record, state='claimed'):
                    (runtime.data / HANDOFF).unlink()
                    runner.safe_log(attempt, 'start', 'The controlled service claimed the start request.')
                    return {'schema_version': 1, 'result': 'started', 'attempt_id': attempt}
                if claimed != record:
                    raise HealthError('Service start acknowledgement does not match this request.')
            time.sleep(.05)
        raise HealthError('Controlled service start acknowledgement is missing.')
    except (HealthError, OSError, ValueError, IndexError, subprocess.TimeoutExpired) as caught:
        error = caught if isinstance(caught, HealthError) else HealthError('Controlled service start could not complete.')
        if controlled:
            return runner.failure(attempt, 'start', error)
        runner.safe_log(attempt, 'start', str(error) + ' Production failure is unconfirmed; inspect the private handoff.')
        return {'schema_version': 1, 'result': 'uncertain', 'attempt_id': attempt, 'phase': 'start',
                'failure_confirmed': False, 'message': str(error)}


def live_process_group(group):
    for entry in Path('/proc').iterdir():
        if not entry.name.isdecimal():
            continue
        try:
            fields = (entry / 'stat').read_text().rsplit(') ', 1)[1].split()
            if fields[0] != 'Z' and int(fields[2]) == group:
                return True
        except (OSError, ValueError, IndexError):
            continue
    return False


class ProcessTransport:
    """One fixed command per action. The command boundary also supports local peers."""
    def __init__(self, command):
        self.command = command
        self.in_worker = False

    def exchange(self, action, request, timeout, receive):
        payload = json_bytes(request) + b'\n'
        if len(payload) > REQUEST_LIMIT or timeout <= 0:
            raise HealthError('Control budget is exhausted.')
        command, environment = self.command(action)
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   env=environment, start_new_session=not self.in_worker, close_fds=True)
        deadline = time.monotonic() + timeout
        selector = selectors.DefaultSelector()
        for pipe, event in [(process.stdin, selectors.EVENT_WRITE), (process.stdout, selectors.EVENT_READ),
                            (process.stderr, selectors.EVENT_READ)]:
            os.set_blocking(pipe.fileno(), False)
            selector.register(pipe, event)
        sent = 0
        try:
            while selector.get_map():
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise HealthError('SSH operation exceeded its time budget.')
                for key, event in selector.select(min(remaining, .2)):
                    pipe = key.fileobj
                    if pipe is process.stdin:
                        sent += os.write(pipe.fileno(), payload[sent:])
                        if sent == len(payload):
                            selector.unregister(pipe)
                            pipe.close()
                    else:
                        block = os.read(pipe.fileno(), 65536)
                        if not block:
                            selector.unregister(pipe)
                            pipe.close()
                        elif pipe is process.stdout:
                            receive(block)
                        # stderr is drained and discarded, never an unbounded diagnostic buffer.
            remaining = deadline - time.monotonic()
            if remaining <= 0 or process.wait(timeout=remaining) != 0:
                raise HealthError('SSH operation did not finish successfully.')
        except (OSError, subprocess.TimeoutExpired) as error:
            raise HealthError('SSH operation could not complete.') from None
        finally:
            selector.close()
            if process.poll() is None:
                if self.in_worker:
                    process.kill()
                else:
                    os.killpg(process.pid, signal.SIGKILL)
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    raise HealthError('SSH process end is unconfirmed.', uncertain=True) from None
            for pipe in (process.stdin, process.stdout, process.stderr):
                if not pipe.closed:
                    pipe.close()

    def control(self, action, request, timeout):
        data = bytearray()
        def receive(block):
            if len(data) + len(block) > RESPONSE_LIMIT:
                raise HealthError('Control response exceeds its limit.')
            data.extend(block)
        self.exchange(action, request, timeout, receive)
        if not data.endswith(b'\n') or data.count(b'\n') != 1:
            raise HealthError('Control response is incomplete.')
        result = decode_object(data, RESPONSE_LIMIT)
        if type(result.get('schema_version')) is not int or result['schema_version'] != 1:
            raise HealthError('Control response version is unsupported.')
        return result


class SystemRuntime:
    """Read Linux resource/filesystem facts; never configure the host at runtime."""
    def __init__(self, data, logs, *, proc_root=Path('/proc'), cgroup_root=Path('/sys/fs/cgroup'), sys_root=Path('/sys')):
        self.data, self.logs = Path(data), Path(logs)
        self.proc, self.cgroups, self.sys = Path(proc_root), Path(cgroup_root), Path(sys_root)

    def private_directory(self, path):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise HealthError('Private directory ownership or permissions are invalid.')

    def verify_resources(self, limits):
        self.private_directory(self.data)
        self.private_directory(self.logs)
        if (self.proc / 'self/cgroup').read_text().strip() != '0::' + GROUP:
            raise HealthError('The process is outside the controlled service.')
        mount = str(self.cgroups.resolve())
        mounts = [tuple(part.split() for part in line.split(' - ', 1))
                  for line in (self.proc / 'self/mountinfo').read_text().splitlines() if ' - ' in line]
        if not any(len(left) >= 6 and len(right) >= 3 and left[4] == mount and right[0] == 'cgroup2'
                   for left, right in mounts):
            raise HealthError('The unified resource filesystem is unavailable.')
        group = self.cgroups / GROUP.lstrip('/')
        if not group.resolve().is_relative_to(self.cgroups.resolve()) or (group / 'cgroup.type').read_text().strip() != 'domain':
            raise HealthError('The controlled resource group is invalid.')
        cpu = (group / 'cpu.max').read_text().split()
        if len(cpu) != 2 or not all(value.isdecimal() for value in cpu) or int(cpu[1]) <= 0 \
                or int(cpu[0]) * 100 != int(cpu[1]) * limits.cpu_quota_percent \
                or (group / 'cpu.weight').read_text().strip() != str(limits.cpu_weight):
            raise HealthError('The shared CPU limits do not match configuration.')
        device = self.data.stat().st_dev
        block = (self.sys / f'dev/block/{os.major(device)}:{os.minor(device)}').resolve(strict=True)
        if not block.is_relative_to((self.sys / 'devices').resolve()):
            raise HealthError('The destination device is unproven.')
        if (block / 'partition').exists():
            block = block.parent
        if not (block / 'slaves').is_dir() or any((block / 'slaves').iterdir()):
            raise HealthError('The destination storage topology is unsupported.')
        backing = (block / 'dev').read_text().strip()
        if not re.fullmatch(r'[0-9]+:[0-9]+', backing):
            raise HealthError('The destination device identity is invalid.')
        rows = [line.split() for line in (group / 'io.max').read_text().splitlines()]
        rows = [row for row in rows if row and row[0] == backing]
        if len(rows) != 1:
            raise HealthError('The destination I/O limits are absent.')
        pairs = [item.split('=', 1) for item in rows[0][1:]]
        if any(len(pair) != 2 for pair in pairs) or len({pair[0] for pair in pairs}) != len(pairs):
            raise HealthError('The destination I/O limits are invalid.')
        values = dict(pairs)
        if values.get('rbps') != str(limits.read_bytes_per_second) or values.get('wbps') != str(limits.write_bytes_per_second):
            raise HealthError('The shared destination I/O limits do not match configuration.')

    def free_bytes(self):
        info = os.statvfs(self.data)
        return info.f_bavail * info.f_frsize

    def log(self, attempt, phase, message):
        if not re.fullmatch(r'[a-f0-9]{32}', attempt):
            return
        self.private_directory(self.logs)
        descriptor = private_descriptor(self.logs / 'run.log', os.O_WRONLY | os.O_APPEND | os.O_CREAT)
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_nlink != 1:
                raise HealthError('Private log ownership or permissions are invalid.')
            line = f'{datetime.now(timezone.utc).isoformat()} {attempt} {phase}: {message}\n'
            os.write(descriptor, line.encode('utf-8'))
        finally:
            os.close(descriptor)


class ArchiveHealthRunner:
    def __init__(self, limits, runtime, transport):
        self.limits, self.runtime, self.transport = limits, runtime, transport

    def run(self, start_kind, attempt_id=None):
        if start_kind not in ('manual', 'scheduled'):
            raise HealthError('Start kind is invalid.')
        attempt = attempt_id or uuid.uuid4().hex
        if not re.fullmatch(r'[a-f0-9]{32}', attempt):
            raise HealthError('Attempt identity is invalid.')
        lock, directory, request = None, None, None
        export_requested, result_ready, phase = False, False, 'start'
        try:
            self.limits.validate()
            self.runtime.verify_resources(self.limits)
            # Both timer and dispatched starts reserve the run before releasing the dispatch lock.
            with private_lock(self.runtime.data / '.dispatch.lock', self.limits.control_seconds):
                lock = private_descriptor(self.runtime.data / '.run.lock', os.O_RDWR | os.O_CREAT)
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    raise HealthError('Another local archive run is active.') from None
                handoff = self.runtime.data / HANDOFF
                if handoff.exists() or handoff.is_symlink():
                    record = read_handoff(self.runtime.data)
                    identity = requester_identity(record['pid'])
                    if record['state'] != 'pending' or any(record[key] != value for key, value in identity.items()) \
                            or not 0 <= time.monotonic() - record['issued'] <= self.limits.control_seconds:
                        raise HealthError('The previous start requester needs inspection.')
                    attempt, start_kind = record['attempt_id'], record['start_kind']
                    write_handoff(self.runtime.data, dict(record, state='claimed'))
                for item in self.runtime.data.iterdir():
                    if item.name == HANDOFF:
                        record = read_handoff(self.runtime.data)
                        if record['state'] != 'claimed' or record['attempt_id'] != attempt or record['start_kind'] != start_kind:
                            raise HealthError('A previous private start still needs inspection.')
                    elif item.name not in ('.run.lock', '.dispatch.lock'):
                        raise HealthError('A previous private run still needs inspection.')
            self.runtime.log(attempt, 'start', 'Controlled archive verification starting.')
            status = self.transport.control('status', {'schema_version': 1, 'attempt_id': None}, self.limits.control_seconds)
            target = status.get('target', {})
            if not isinstance(target, dict) or not isinstance(status.get('state'), dict) \
                    or not isinstance(target.get('pending'), dict) or not isinstance(target.get('protection'), dict):
                raise HealthError('Production status is incomplete.')
            if status.get('result') != 'ok' or status.get('command') != 'status' \
                    or status.get('state', {}).get('active_attempt_id', 'unknown') is not None \
                    or status.get('active_attempt', 'unknown') is not None \
                    or target.get('available') is not True or target.get('generation_locked') is not False \
                    or target.get('pending', {}).get('allowed') is not True or target.get('protection', {}).get('allowed') is not True:
                raise HealthError('Production is not ready for a new archive attempt.')
            size, generation = target.get('bytes'), target.get('generation')
            if type(size) is not int or size <= 0 or not isinstance(generation, str) or not GENERATION.fullmatch(generation):
                raise HealthError('Production target is invalid.')
            if size > self.limits.source_bytes_per_second * self.limits.copy_seconds \
                    or (self.limits.max_archive_bytes and size > self.limits.max_archive_bytes):
                raise HealthError('Archive size exceeds the configured copy capacity.')
            if self.runtime.free_bytes() < size + self.limits.buffer_bytes + self.limits.reserve_bytes:
                raise HealthError('Insufficient private filesystem space before copying.')
            directory = self.runtime.data / attempt
            directory.mkdir(mode=0o700)
            partial = directory / 'archive.sqlite.partial'
            request = {'schema_version': 1, 'attempt_id': attempt, 'start_kind': start_kind,
                       'generation': generation, 'expected_bytes': size}
            self.evidence(directory, 'request.json', request)
            started = time.monotonic()
            export_requested, phase = True, 'copy'
            proof = self.work(lambda: self.receive(partial, request), self.limits.copy_seconds, 'copy')
            phase = 'verification'
            if self.runtime.free_bytes() < self.limits.reserve_bytes:
                raise HealthError('Filesystem reserve is too small before verification.', 'verification')
            remaining = self.limits.copy_verification_seconds - (time.monotonic() - started)
            identity = self.work(lambda: self.verify(partial, size, proof['sha256']), remaining, 'verification')
            archive = directory / 'archive.sqlite'
            if file_identity(partial.lstat()) != identity:
                raise HealthError('Stored file identity changed after verification.', 'verification')
            partial.rename(archive)
            archive.chmod(0o400)
            identity = file_identity(archive.lstat())
            phase = 'check'
            if self.runtime.free_bytes() < self.limits.reserve_bytes:
                raise HealthError('Filesystem reserve is too small before SQLite.', 'check')
            outcome = self.work(lambda: self.check(archive, identity), self.limits.check_seconds, 'check')
            result_ready, phase = True, 'result'
            result = {'schema_version': 1, 'attempt_id': attempt, 'generation': generation, 'received_bytes': size,
                      'sha256': proof['sha256'], 'transfer_complete': True, 'check': 'quick_check',
                      'check_completed': True, 'outcome': outcome}
            self.evidence(directory, 'result.json', result)
            acknowledgement = self.confirm_result(result)
            removed = self.cleanup(directory, attempt)
            self.safe_log(attempt, 'result', 'Production accepted ' + outcome + '; '
                          + ('private copy removed.' if removed else 'private cleanup needs attention.'))
            return acknowledgement
        except (HealthError, OSError, ValueError) as caught:
            error = caught if isinstance(caught, HealthError) else HealthError('A required local operation failed.', phase)
            return self.failure(attempt, phase, error, request if export_requested else None, directory, result_ready)
        finally:
            if lock is not None:
                os.close(lock)

    def failure(self, attempt, phase, error, request=None, directory=None, result_ready=False):
        confirmed = False
        if not result_ready and not error.uncertain:
            failed = {'schema_version': 1, 'attempt_id': attempt if request else None,
                      'phase': phase if request else 'start', 'reason_code': error.reason, 'message': str(error)}
            try:
                ack = self.transport.control('fail', failed, self.limits.control_seconds)
                confirmed = self.failed(ack, request)
            except (HealthError, OSError, ValueError):
                pass
        if confirmed and directory is not None:
            self.cleanup(directory, attempt)
        self.safe_log(attempt, phase, str(error) + (' Failure confirmed.' if confirmed else ' Retained for manual inspection.'))
        return {'schema_version': 1, 'result': 'failed' if confirmed else 'uncertain', 'attempt_id': attempt,
                'phase': phase, 'failure_confirmed': confirmed, 'message': str(error)}

    def safe_log(self, attempt, phase, message):
        try:
            self.runtime.log(attempt, phase, message)
        except (HealthError, OSError):
            pass  # A failed local log is never replaced by an invented Production event.

    def cleanup(self, directory, attempt):
        try:
            if directory.parent != self.runtime.data or directory.name != attempt:
                raise HealthError('Private cleanup identity is invalid.')
            self.runtime.private_directory(directory)
            for name in ('archive.sqlite', 'archive.sqlite.partial', 'request.json', 'result.json'):
                path = directory / name
                if path.exists() or path.is_symlink():
                    path.unlink()
            directory.rmdir()
            return True
        except (HealthError, OSError):
            self.safe_log(attempt, 'cleanup', 'Confirmed attempt has a private file that could not be removed.')
            return False

    @staticmethod
    def failed(ack, request):
        if type(ack.get('schema_version')) is not int or ack['schema_version'] != 1:
            return False
        if request is None:
            return ack.get('result') == 'failed' and ack.get('incident_action') in ('raised', 'repeated', 'none')
        attempt = ack.get('attempt', {})
        return ack.get('result') == 'closed' and isinstance(attempt, dict) \
            and type(attempt.get('expected_bytes')) is int \
            and all(attempt.get(key) == request[key] for key in ('attempt_id', 'generation', 'expected_bytes')) \
            and attempt.get('status') == 'closed' and attempt.get('outcome') == 'technical_failure' \
            and valid_time(attempt.get('invalidated_at')) and valid_time(attempt.get('closed_at')) \
            and attempt.get('accepted_at') is None

    @staticmethod
    def evidence(directory, name, value):
        descriptor = open_private(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL)
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(json_bytes(value) + b'\n')
            stream.flush()
            os.fsync(stream.fileno())

    def receive(self, partial, request):
        self.transport.in_worker = True
        expected = {'schema_version': 1, 'type': 'archive', 'attempt_id': request['attempt_id'],
                    'generation': request['generation'], 'bytes': request['expected_bytes']}
        buffer = bytearray()
        stage, remaining, trailer = 'header', request['expected_bytes'], None
        descriptor = open_private(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL)
        with os.fdopen(descriptor, 'wb') as stream:
            def consume(block):
                nonlocal stage, remaining, trailer
                buffer.extend(block)
                while buffer:
                    if stage == 'bytes':
                        count = min(remaining, len(buffer))
                        stream.write(buffer[:count])
                        del buffer[:count]
                        remaining -= count
                        if remaining:
                            break
                        stage = 'trailer'
                    elif stage in ('header', 'trailer'):
                        end = buffer.find(b'\n')
                        if end < 0:
                            if len(buffer) > REQUEST_LIMIT:
                                raise HealthError('Export frame exceeds its limit.', 'copy')
                            break
                        frame = decode_object(buffer[:end + 1], REQUEST_LIMIT)
                        del buffer[:end + 1]
                        if stage == 'header':
                            if frame != expected or type(frame.get('bytes')) is not int or type(frame.get('schema_version')) is not int:
                                raise HealthError('Export descriptor does not match the requested attempt.', 'copy')
                            stage = 'bytes'
                        else:
                            expected_end = dict(expected, type='complete')
                            sha = frame.get('sha256')
                            if set(frame) != set(expected_end) | {'sha256'} or any(frame[key] != value for key, value in expected_end.items()) \
                                    or type(frame.get('bytes')) is not int or type(frame.get('schema_version')) is not int \
                                    or not isinstance(sha, str) or not re.fullmatch(r'[a-f0-9]{64}', sha):
                                raise HealthError('Export terminal proof is invalid.', 'copy')
                            trailer, stage = frame, 'done'
                    else:
                        raise HealthError('Export has unexpected trailing data.', 'copy')
            self.transport.exchange('export', request, self.limits.copy_seconds, consume)
            if stage != 'done' or buffer or remaining:
                raise HealthError('Export ended without its complete proof.', 'copy')
            stream.flush()
            os.fsync(stream.fileno())
        return trailer

    @staticmethod
    def verify(path, size, digest):
        descriptor = open_private(path, os.O_RDONLY)
        with os.fdopen(descriptor, 'rb') as stream:
            before = os.fstat(stream.fileno())
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size != size:
                raise HealthError('Stored archive size or identity is invalid.', 'verification')
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
            after = os.fstat(stream.fileno())
            if actual != digest or file_identity(before) != file_identity(after) or file_identity(path.lstat()) != file_identity(after):
                raise HealthError('Stored archive checksum or identity does not match.', 'verification')
            return file_identity(after)

    @staticmethod
    def check(path, identity):
        descriptor = open_private(path, os.O_RDONLY)
        try:
            if file_identity(os.fstat(descriptor)) != identity or identity[2] & 0o222:
                raise HealthError('Verified archive is no longer immutable.', 'check')
            connection = sqlite3.connect(f'file:/proc/self/fd/{descriptor}?mode=ro&immutable=1', uri=True)
            try:
                connection.execute('PRAGMA query_only=ON')
                count, healthy = 0, True
                for row in connection.execute('PRAGMA quick_check'):
                    if len(row) != 1 or not isinstance(row[0], str) or not row[0]:
                        raise HealthError('SQLite returned an incomplete finding.', 'check')
                    count += 1
                    healthy = healthy and row[0] == 'ok'
                if count == 0:
                    raise HealthError('SQLite returned no complete finding.', 'check')
            finally:
                connection.close()
            if file_identity(os.fstat(descriptor)) != identity or file_identity(path.lstat()) != identity:
                raise HealthError('Verified archive changed during SQLite.', 'check')
            return 'ok' if healthy and count == 1 else 'corruption'
        except sqlite3.Error:
            raise HealthError('SQLite could not complete the read-only check.', 'check') from None
        finally:
            os.close(descriptor)

    @staticmethod
    def accepted(acknowledgement, request):
        return set(acknowledgement) == {'schema_version', 'command', 'result', 'attempt_id', 'generation', 'result_fingerprint',
                                       'outcome', 'accepted_at', 'protection'} \
            and type(acknowledgement['schema_version']) is int and acknowledgement['schema_version'] == 1 \
            and acknowledgement['command'] == 'result' and acknowledgement['result'] == 'accepted' \
            and all(acknowledgement[key] == request[key] for key in ('attempt_id', 'generation', 'outcome')) \
            and acknowledgement['result_fingerprint'] == fingerprint(request) and valid_time(acknowledgement['accepted_at']) \
            and isinstance(acknowledgement['protection'], dict) and set(acknowledgement['protection']) == PROTECTION_FIELDS \
            and all(type(value) is bool for value in acknowledgement['protection'].values()) \
            and acknowledgement['protection']['pending'] is False

    def confirm_result(self, request):
        deadline = time.monotonic() + self.limits.result_seconds
        def call(action, payload):
            return self.transport.control(action, payload, min(self.limits.control_seconds, deadline - time.monotonic()))
        try:
            acknowledgement = call('result', request)
            if self.accepted(acknowledgement, request):
                return acknowledgement
        except (HealthError, OSError, ValueError):
            pass
        try:
            status = call('status', {'schema_version': 1, 'attempt_id': request['attempt_id']})
            attempt = status.get('attempt')
            if status.get('command') == 'status' and status.get('result') == 'ok' and isinstance(attempt, dict) \
                    and all(attempt.get(key) == request[key] for key in ('attempt_id', 'generation', 'received_bytes', 'sha256', 'outcome')) \
                    and type(attempt.get('received_bytes')) is int and attempt.get('transfer_complete') is True \
                    and attempt.get('status') == 'closed' and attempt.get('result_fingerprint') == fingerprint(request) \
                    and valid_time(attempt.get('accepted_at')):
                # Status may select a different current target. Never label its gates as this attempt's gates.
                return {'schema_version': 1, 'command': 'result', 'result': 'accepted',
                        'attempt_id': attempt['attempt_id'], 'generation': attempt['generation'],
                        'result_fingerprint': attempt['result_fingerprint'], 'outcome': attempt['outcome'],
                        'accepted_at': attempt['accepted_at'], 'protection': None, 'confirmed_via_status': True}
        except (HealthError, OSError, ValueError):
            pass
        try:
            acknowledgement = call('result', request)  # The only identical small-message retry.
            if self.accepted(acknowledgement, request):
                return acknowledgement
        except (HealthError, OSError, ValueError):
            pass
        raise HealthError('Result acceptance remains unconfirmed.', 'result', uncertain=True)

    @staticmethod
    def work(operation, timeout, phase):
        if timeout <= 0:
            raise HealthError('Phase time budget is exhausted.', phase)
        reader, writer = os.pipe()
        pid = os.fork()
        if pid == 0:
            os.close(reader)
            os.setsid()
            try:
                response = {'ok': True, 'value': operation()}
            except HealthError as error:
                response = {'ok': False, 'message': str(error), 'reason': error.reason, 'uncertain': error.uncertain}
            except BaseException:
                response = {'ok': False, 'message': 'The phase could not complete.', 'reason': 'health_run_failed', 'uncertain': False}
            try:
                os.write(writer, json_bytes(response) + b'\n')
            finally:
                os.close(writer)
            os._exit(0)
        os.close(writer)
        os.set_blocking(reader, False)
        selector = selectors.DefaultSelector()
        selector.register(reader, selectors.EVENT_READ)
        deadline, data, status, pipe_closed = time.monotonic() + timeout, bytearray(), None, False
        try:
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise HealthError('Phase exceeded its time budget.', phase,
                                      'health_child_timeout' if phase == 'check' else 'health_run_failed')
                if not pipe_closed and selector.select(min(.05, remaining)):
                    block = os.read(reader, REQUEST_LIMIT + 1)
                    data.extend(block)
                    if len(data) > REQUEST_LIMIT:
                        raise HealthError('Phase completion message is invalid.', phase)
                    if not block:
                        pipe_closed = True
                        selector.unregister(reader)
                if status is None:
                    ended, result = os.waitpid(pid, os.WNOHANG)
                    if ended:
                        status = result
                if pipe_closed and status is not None:
                    break
                if pipe_closed:
                    time.sleep(min(.01, max(0, deadline - time.monotonic())))
            if not os.WIFEXITED(status) or os.WEXITSTATUS(status) != 0:
                raise HealthError('Phase process ended without completion.', phase)
            if live_process_group(pid):
                raise HealthError('Phase left an unfinished helper process.', phase)
            response = decode_object(data, REQUEST_LIMIT)
            if response.get('ok') is not True:
                raise HealthError(response.get('message', 'Phase did not complete.'), phase,
                                  response.get('reason', 'health_run_failed'), response.get('uncertain') is True)
            return response['value']
        finally:
            selector.close()
            os.close(reader)
            if status is None or live_process_group(pid):
                try:
                    os.killpg(pid, signal.SIGKILL)
                except ProcessLookupError:
                    # A deadline may arrive before the new child has completed setsid().
                    if status is None:
                        try:
                            os.kill(pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                stopped_by = time.monotonic() + 2
                while time.monotonic() < stopped_by:
                    if status is None:
                        ended, result = os.waitpid(pid, os.WNOHANG)
                        if ended:
                            status = result
                    if status is not None and not live_process_group(pid):
                        break
                    time.sleep(.01)
                if status is None or live_process_group(pid):
                    raise HealthError('Phase process end is unconfirmed.', phase, uncertain=True)


def main():
    parser = argparse.ArgumentParser(description='Start the controlled private archive verification service.')
    parser.add_argument('command', choices=['run'])
    kind = parser.add_mutually_exclusive_group(required=True)
    kind.add_argument('--manual', action='store_true')
    kind.add_argument('--scheduled', action='store_true')
    arguments = parser.parse_args()
    os.environ.clear()
    os.environ.update(clean_environment())
    runtime = SystemRuntime(Path('/var/lib/kiwi-archive-health'), Path('/var/log/kiwi-archive-health'))
    try:
        if os.geteuid() == 0 or os.geteuid() != pwd.getpwnam('kiwi-archive-health').pw_uid:
            raise HealthError('Run this command as the dedicated archive-health user.')
        configuration = load_configuration()
        runner = ArchiveHealthRunner(configuration.limits, runtime, ProcessTransport(configuration.ssh_command))
        result = start_run(runner, 'manual' if arguments.manual else 'scheduled')
    except (HealthError, OSError, ValueError, KeyError):
        attempt = uuid.uuid4().hex
        result = {'schema_version': 1, 'result': 'uncertain', 'attempt_id': attempt, 'phase': 'start', 'failure_confirmed': False,
                  'message': 'Dedicated user, private files or shared configuration could not be validated.'}
        try:
            runtime.log(attempt, 'start', result['message'] + ' Production failure is unconfirmed.')
        except (HealthError, OSError):
            pass
    print(json_bytes(result).decode('utf-8'))
    return 0 if result['result'] in ('accepted', 'started') else 2 if result['result'] == 'failed' else 3


if __name__ == '__main__':
    sys.exit(main())
