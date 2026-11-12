#!/usr/bin/env python3
"""Small Linux runner/real PHP transport fixtures. No production host or archive.

Run: KIWI_RETENTION_TEST_CREDENTIALS_FILE=/private/test-credentials.json python3 tests/archive-health-vps-tests.py
Credentials use the existing state-integration fixture's loopback MariaDB JSON contract.
Only random codex_test_157_* databases and /tmp/kiwi-attempt-* synthetic files are created/removed.
"""
import importlib.util
import fcntl
import io
from dataclasses import replace
import json
import os
from pathlib import Path
import secrets
import sqlite3
import subprocess
import sys
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parent.parent
SOURCE = REPO / 'tools/database/archive-health-vps/kiwi_retention_archive_health.py'
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('kiwi_archive_health', SOURCE)
health = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = health
spec.loader.exec_module(health)


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='kiwi-attempt-vps-')
        self.root = Path(self.temp.name)
        self.archive = self.root / 'kiwi_retention_archive_2026.sqlite'
        with sqlite3.connect(self.archive) as db:
            db.execute('CREATE TABLE fixture (id INTEGER PRIMARY KEY)')
            db.execute('INSERT INTO fixture VALUES (1)')
        self.database = 'codex_test_157_' + secrets.token_hex(6)
        self.db_fixture('setup')
        self.addCleanup(self.close)
        self.data = self.root / 'copies'
        self.logs = self.root / 'logs'
        self.data.mkdir(mode=0o700)
        self.logs.mkdir(mode=0o700)
        self.limits = health.Limits()
        self.runtime = self.resource_fixture()
        self.transport = health.ProcessTransport(self.command)

    def db_fixture(self, action):
        subprocess.run(['php', str(REPO / 'tests/fixtures/retention-health-runner-db.php'), action, self.database],
                       check=True, capture_output=True)

    def close(self):
        self.db_fixture('drop')
        self.temp.cleanup()

    def command(self, action):
        env = dict(os.environ, SSH_ORIGINAL_COMMAND=action)
        return (['php', str(REPO / 'tests/retention-archive-state-integration-tests.php'), '--transport',
                 self.database, str(self.archive)], env)

    def resource_fixture(self):
        cgroup = self.root / 'cgroup'
        group = cgroup / 'system.slice/kiwi-retention-archive-health.service'
        group.mkdir(parents=True)
        for name, value in {'cgroup.type': 'domain', 'cpu.max': '50000 100000', 'cpu.weight': '10',
                            'io.max': '8:0 rbps=16777216 wbps=8388608 riops=max wiops=max'}.items():
            (group / name).write_text(value + '\n')
        proc = self.root / 'proc/self'
        proc.mkdir(parents=True)
        (proc / 'cgroup').write_text('0::/system.slice/kiwi-retention-archive-health.service\n')
        (proc / 'mountinfo').write_text(f'1 0 0:1 / {cgroup} rw - cgroup2 cgroup rw\n')
        sysroot = self.root / 'sys'
        disk = sysroot / 'devices/block/example'
        partition = disk / 'part'
        partition.mkdir(parents=True)
        (disk / 'dev').write_text('8:0\n')
        (disk / 'slaves').mkdir()
        (partition / 'partition').write_text('1\n')
        (sysroot / 'dev/block').mkdir(parents=True)
        device = os.stat(self.data).st_dev
        (sysroot / f'dev/block/{os.major(device)}:{os.minor(device)}').symlink_to(partition)
        return health.SystemRuntime(self.data, self.logs, proc_root=self.root / 'proc',
                                    cgroup_root=cgroup, sys_root=sysroot)

    def status(self, attempt=None):
        return self.transport.control('status', {'schema_version': 1, 'attempt_id': attempt}, 5)

    def peer(self, mode):
        def command(action):
            return ([sys.executable, str(REPO / 'tests/fixtures/retention-health-runner-peer.py'), mode,
                     str(REPO / 'tests/retention-archive-state-integration-tests.php'), self.database,
                     str(self.archive), action], dict(os.environ))
        return health.ProcessTransport(command)

    def test_complete_small_run_is_accepted_and_copy_removed(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        self.assertEqual('accepted', result['result'])
        self.assertEqual('ok', result['outcome'])
        status = self.status(result['attempt_id'])
        self.assertIsNone(status['state']['active_attempt_id'])
        self.assertEqual(result['attempt_id'], status['state']['last_confirmed_attempt_id'])
        self.assertEqual('closed', status['attempt']['status'])
        self.assertEqual(self.archive.stat().st_size, status['attempt']['received_bytes'])
        self.assertFalse((self.data / result['attempt_id']).exists())
        self.assertIn(result['attempt_id'], (self.logs / 'run.log').read_text())

    def test_unapplied_resource_limits_report_failure_without_starting(self):
        group = self.runtime.cgroups / health.GROUP.lstrip('/')
        (group / 'cpu.max').write_text('max 100000\n')
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('scheduled')
        self.assertEqual('failed', result['result'])
        self.assertTrue(result['failure_confirmed'])
        status = self.status()
        self.assertIsNone(status['state']['active_attempt_id'])
        self.assertEqual(0, status['state']['last_attempt_sequence'])
        self.assertEqual('health_run_failed', status['open_incidents'][0]['reason_code'])
        self.assertNotIn(str(self.root), (self.logs / 'run.log').read_text())

    def test_lost_result_acknowledgement_reads_the_original_accepted_result(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('lost-result-ack')).run('manual')
        self.assertEqual('accepted', result['result'])
        status = self.status(result['attempt_id'])
        self.assertEqual(status['attempt']['accepted_at'], result['accepted_at'])
        self.assertFalse((self.data / result['attempt_id']).exists())
        calls = json.loads((self.root / 'peer-requests.json').read_text())
        self.assertEqual(['status', 'export', 'result', 'status'], calls)

    def test_result_is_retried_only_once_after_status(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('retry-result')).run('manual')
        self.assertEqual('accepted', result['result'])
        self.assertEqual(['status', 'export', 'result', 'status', 'result'],
                         json.loads((self.root / 'peer-requests.json').read_text()))
        self.assertEqual(1, self.status()['state']['last_attempt_sequence'])

    def test_unknown_result_retains_copy_and_prevents_another_export(self):
        runner = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('uncertain-result'))
        result = runner.run('manual')
        self.assertEqual('uncertain', result['result'])
        copied = self.data / result['attempt_id'] / 'archive.sqlite'
        self.assertTrue(copied.exists())
        self.assertTrue((copied.parent / 'result.json').exists())
        self.assertEqual(result['attempt_id'], self.status()['state']['active_attempt_id'])
        runner.run('scheduled')
        self.assertTrue(copied.exists())
        self.assertEqual(1, json.loads((self.root / 'peer-requests.json').read_text()).count('export'))
        # Explicit operator action through the existing capability, not an automatic runner retry/cleanup.
        aborted = subprocess.run(['php', str(REPO / 'tests/retention-archive-state-integration-tests.php'),
                                  '--operator-abort', self.database, str(self.archive)],
                                 input=json.dumps({'attempt_id': result['attempt_id'], 'confirm': True}).encode(),
                                 capture_output=True, check=True)
        self.assertEqual('closed', json.loads(aborted.stdout)['result'])
        self.assertEqual('closed', self.status(result['attempt_id'])['attempt']['status'])
        self.assertTrue(copied.exists())
        for name in ('archive.sqlite', 'request.json', 'result.json'):
            (copied.parent / name).unlink()
        copied.parent.rmdir()
        restarted = health.start_run(health.ArchiveHealthRunner(self.limits, self.runtime, self.transport), 'manual')
        self.assertEqual('accepted', restarted['result'])
        self.assertNotEqual(result['attempt_id'], restarted['attempt_id'])
        self.assertEqual(2, self.status()['state']['last_attempt_sequence'])

    def test_receiver_rejects_tampering_before_a_finding_and_closes_known_failures(self):
        for mode in ('changed-byte', 'truncated-header', 'truncated-bytes', 'missing-trailer', 'extra-output', 'wrong-size', 'duplicate-header',
                     'wrong-trailer-id', 'nonzero-export'):
            with self.subTest(mode=mode):
                self.db_fixture('drop')
                self.db_fixture('setup')
                result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer(mode)).run('manual')
                self.assertEqual('failed', result['result'])
                self.assertTrue(result['failure_confirmed'])
                status = self.status(result['attempt_id'])
                self.assertEqual('technical_failure', status['attempt']['outcome'])
                self.assertIsNone(status['state']['last_confirmed_attempt_id'])
                self.assertFalse((self.data / result['attempt_id']).exists())
                self.assertEqual('verification' if mode == 'changed-byte' else 'copy', status['attempt']['phase'])

    def test_failed_failure_acknowledgement_retains_pending_and_partial_copy(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('changed-byte-fail-unavailable')).run('manual')
        self.assertEqual('uncertain', result['result'])
        self.assertFalse(result['failure_confirmed'])
        self.assertEqual(result['attempt_id'], self.status()['state']['active_attempt_id'])
        self.assertTrue((self.data / result['attempt_id'] / 'archive.sqlite.partial').exists())

    def test_malformed_failure_acknowledgement_cannot_authorize_copy_deletion(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('changed-byte-malformed-fail')).run('manual')
        self.assertEqual('uncertain', result['result'])
        self.assertFalse(result['failure_confirmed'])
        self.assertEqual('closed', self.status(result['attempt_id'])['attempt']['status'])
        self.assertTrue((self.data / result['attempt_id'] / 'archive.sqlite.partial').exists())

    def test_completed_non_ok_sqlite_finding_is_accepted_with_corruption_protection(self):
        contents = bytearray(self.archive.read_bytes())
        self.assertEqual(bytes(8), contents[32:40])
        contents[36:40] = (1).to_bytes(4, 'big')
        self.archive.write_bytes(contents)
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        self.assertEqual('accepted', result['result'])
        self.assertEqual('corruption', result['outcome'])
        status = self.status(result['attempt_id'])
        self.assertEqual('corruption', status['attempt']['outcome'])
        self.assertTrue(status['target']['protection']['corruption_write_blocked'])
        self.assertFalse((self.data / result['attempt_id']).exists())

    def test_sqlite_exception_is_technical_not_corruption(self):
        self.archive.write_bytes(b'not a SQLite database' * 100)
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        status = self.status(result['attempt_id'])
        self.assertEqual('failed', result['result'])
        self.assertEqual('technical_failure', status['attempt']['outcome'])
        self.assertEqual('check', status['attempt']['phase'])
        self.assertIsNone(status['state']['last_confirmed_attempt_id'])

    def test_stalled_copy_reaps_its_processes_before_safe_failure_cleanup(self):
        started = time.monotonic()
        limits = replace(self.limits, copy_seconds=2, copy_verification_seconds=4)
        result = health.ArchiveHealthRunner(limits, self.runtime, self.peer('stalled-export')).run('manual')
        self.assertLess(time.monotonic() - started, 5)
        self.assertEqual('failed', result['result'])
        self.assertFalse((self.data / result['attempt_id']).exists())
        pid = (self.root / 'peer-child.pid').read_text()
        state_file = Path('/proc') / pid / 'stat'
        self.assertTrue(not state_file.exists() or state_file.read_text().split(') ', 1)[1].startswith('Z'))

    def test_sqlite_timeout_and_process_death_never_become_findings(self):
        for failure in ('timeout', 'exit'):
            with self.subTest(failure=failure):
                self.db_fixture('drop')
                self.db_fixture('setup')
                limits = replace(self.limits, check_seconds=.05)
                def unavailable_sqlite(*args, **kwargs):
                    if failure == 'exit':
                        os._exit(9)
                    time.sleep(5)
                with patch.object(health.sqlite3, 'connect', unavailable_sqlite):
                    result = health.ArchiveHealthRunner(limits, self.runtime, self.transport).run('manual')
                self.assertEqual('failed', result['result'])
                status = self.status(result['attempt_id'])
                self.assertEqual('technical_failure', status['attempt']['outcome'])
                self.assertEqual('health_child_timeout' if failure == 'timeout' else 'health_run_failed', status['attempt']['reason_code'])
                self.assertIsNone(status['state']['last_confirmed_attempt_id'])

    def test_completion_bytes_do_not_end_the_phase_before_the_worker_exits(self):
        original_exit = os._exit
        def delayed_exit(code):
            time.sleep(2)
            original_exit(code)
        limits = replace(self.limits, copy_seconds=1, copy_verification_seconds=20)
        started = time.monotonic()
        with patch.object(health.os, '_exit', delayed_exit):
            result = health.ArchiveHealthRunner(limits, self.runtime, self.transport).run('manual')
        self.assertEqual('failed', result['result'])
        self.assertEqual('copy', result['phase'])
        self.assertLess(time.monotonic() - started, 3)

    def test_configuration_is_data_and_builds_only_the_pinned_ssh_command(self):
        config = self.root / 'health.conf'
        key = self.root / 'key'
        known = self.root / 'known_hosts'
        key.write_text('synthetic fixture key')
        key.chmod(0o600)
        known.write_text('synthetic independently pinned host key')
        known.chmod(0o640)
        value = dict(vars(self.limits), ssh_host='example.invalid', ssh_user='fixture', ssh_port=22,
                     identity_file=str(key), known_hosts_file=str(known))
        config.write_text(json.dumps(value))
        config.chmod(0o640)
        loaded = health.load_configuration(config)
        command, environment = loaded.ssh_command('status')
        self.assertEqual(['/usr/bin/ssh', '-F', '/dev/null'], command[:3])
        self.assertEqual(['example.invalid', 'status'], command[-2:])
        self.assertIn('StrictHostKeyChecking=yes', command)
        self.assertIn('Compression=no', command)
        self.assertIn('ControlMaster=no', command)
        self.assertNotIn('SSH_AUTH_SOCK', environment)
        value['ProxyCommand'] = 'untrusted command'
        config.write_text(json.dumps(value))
        with self.assertRaises(health.HealthError):
            health.load_configuration(config)

    def test_manual_dispatch_enters_the_same_controlled_runner_with_exact_start_permission(self):
        group_file = self.runtime.proc / 'self/cgroup'
        group_file.write_text('0::/user.slice/example.scope\n')
        runner = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport)
        completed = []
        original_iterdir = Path.iterdir
        def acknowledge_during_preflight(path):
            entries = list(original_iterdir(path))
            if path == self.data and any(item.name == '.start-request.json' for item in entries):
                # A real caller removes its claimed handoff only under the separate OS lock.
                with open(self.data / '.dispatch.lock', 'rb') as lock:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:
                        pass
                    else:
                        (self.data / '.start-request.json').unlink()
            return iter(entries)
        def start_service(command, **options):
            self.assertEqual(['/usr/bin/sudo', '-n', '--', '/usr/bin/env', '-i', 'PATH=/usr/bin:/bin',
                              '/usr/bin/systemctl', '--no-block', '--job-mode=fail', 'start',
                              'kiwi-retention-archive-health.service'], command)
            self.assertEqual({'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}, options['env'])
            group_file.write_text('0::' + health.GROUP + '\n')
            with patch.object(Path, 'iterdir', acknowledge_during_preflight):
                completed.append(health.start_run(runner, 'scheduled'))
            group_file.write_text('0::/user.slice/example.scope\n')
            return subprocess.CompletedProcess(command, 0)
        with patch.object(health.subprocess, 'run', start_service):
            dispatched = health.start_run(runner, 'manual')
        self.assertEqual('started', dispatched['result'])
        self.assertEqual('accepted', completed[0]['result'])
        self.assertEqual(dispatched['attempt_id'], completed[0]['attempt_id'])
        self.assertEqual('manual', self.status(dispatched['attempt_id'])['attempt']['start_kind'])
        self.assertFalse((self.data / '.start-request.json').exists())

    def test_start_permission_failure_stays_local_and_never_uses_ssh_outside_the_service(self):
        (self.runtime.proc / 'self/cgroup').write_text('0::/user.slice/example.scope\n')
        runner = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('normal'))
        with patch.dict(os.environ, {'INVOCATION_ID': 'forged', 'TRIGGER_UNIT': health.SERVICE}), \
                patch.object(health.subprocess, 'run', side_effect=PermissionError('fixture unavailable')):
            result = health.start_run(runner, 'manual')
        self.assertEqual('uncertain', result['result'])
        self.assertFalse(result['failure_confirmed'])
        self.assertFalse((self.root / 'peer-requests.json').exists())
        self.assertEqual(0, self.status()['state']['last_attempt_sequence'])
        self.assertTrue((self.data / '.start-request.json').exists())

    def test_bootstrap_configuration_failure_reaches_the_private_log_with_a_run_id(self):
        with patch.dict(os.environ), patch.object(sys, 'argv', ['kiwi-retention-archive-health', 'run', '--scheduled']), \
                patch.object(health.os, 'geteuid', return_value=1000), \
                patch.object(health.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1000)), \
                patch.object(health.SystemRuntime, 'log') as log, patch.object(sys, 'stdout', io.StringIO()):
            self.assertEqual(3, health.main())
        log.assert_called_once()
        attempt, phase, message = log.call_args.args
        self.assertRegex(attempt, r'^[a-f0-9]{32}$')
        self.assertEqual('start', phase)
        self.assertNotIn('/etc/', message)

    def test_resource_readback_refuses_wrong_group_weight_device_and_malformed_mount(self):
        group = self.runtime.cgroups / health.GROUP.lstrip('/')
        cases = [(group / 'cpu.weight', '100\n'), (group / 'io.max', '8:0 rbps=max wbps=8388608\n'),
                 (group / 'io.max', '8:1 rbps=16777216 wbps=8388608\n'),
                 (group / 'cgroup.type', 'threaded\n'),
                 (self.runtime.proc / 'self/cgroup', '0::' + health.GROUP + '-similar\n'),
                 (self.runtime.proc / 'self/mountinfo', 'broken - cgroup2\n')]
        for path, invalid in cases:
            with self.subTest(file=path.name, value=invalid):
                original = path.read_text()
                path.write_text(invalid)
                try:
                    result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
                    self.assertEqual('failed', result['result'])
                    self.assertEqual(0, self.status()['state']['last_attempt_sequence'])
                finally:
                    path.write_text(original)
        (self.logs / 'run.log').unlink()
        os.mkfifo(self.logs / 'run.log', 0o600)
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        self.assertEqual('failed', result['result'])
        self.assertEqual(0, self.status()['state']['last_attempt_sequence'])

    def test_malformed_status_shape_cannot_start_an_export(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('malformed-status')).run('manual')
        self.assertEqual('failed', result['result'])
        self.assertEqual(['status', 'fail'], json.loads((self.root / 'peer-requests.json').read_text()))

    def test_capacity_and_the_three_free_space_boundaries_use_the_actual_target(self):
        size = self.archive.stat().st_size
        reserve = self.limits.reserve_bytes
        need = size + self.limits.buffer_bytes + reserve
        for free, expected, phase in [([need, reserve, reserve], 'accepted', None),
                                      ([need - 1], 'failed', 'start'),
                                      ([need, reserve - 1], 'failed', 'verification'),
                                      ([need, reserve, reserve - 1], 'failed', 'check')]:
            with self.subTest(free=free):
                readings = [SimpleNamespace(f_bavail=value, f_frsize=1) for value in free]
                with patch.object(health.os, 'statvfs', side_effect=readings) as space:
                    result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
                self.assertEqual(expected, result['result'])
                self.assertEqual(len(free), space.call_count)
                self.assertTrue(all(call.args == (self.data,) for call in space.call_args_list))
                if phase:
                    self.assertEqual(phase, result['phase'])
        sequence = self.status()['state']['last_attempt_sequence']
        limits = replace(self.limits, source_bytes_per_second=size // 2 - 1, copy_seconds=2)
        refused = health.ArchiveHealthRunner(limits, self.runtime, self.transport).run('manual')
        self.assertEqual('start', refused['phase'])
        self.assertEqual(sequence, self.status()['state']['last_attempt_sequence'])
        limits = replace(limits, source_bytes_per_second=size // 2)
        self.assertEqual('accepted', health.ArchiveHealthRunner(limits, self.runtime, self.transport).run('manual')['result'])

    def test_weekly_capacity_has_no_old_fixed_size_ceiling(self):
        with patch.object(health.os, 'statvfs', return_value=SimpleNamespace(f_bavail=40000000000, f_frsize=1)):
            result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('above-old-size-ceiling')).run('manual')
        # Only the descriptor is artificial: the real tiny source rejects its mismatching size safely.
        self.assertEqual('copy', result['phase'])
        self.assertEqual(['status', 'export', 'fail'], json.loads((self.root / 'peer-requests.json').read_text()))
        self.assertIsNone(self.status()['state']['last_confirmed_attempt_id'])

    def test_stored_copy_mutation_after_transport_is_rejected_before_sqlite(self):
        reads = 0
        def space(path):
            nonlocal reads
            reads += 1
            if reads == 2:
                copied = next(self.data.glob('*/archive.sqlite.partial'))
                contents = bytearray(copied.read_bytes())
                contents[100] ^= 1
                copied.write_bytes(contents)
            return SimpleNamespace(f_bavail=40000000000, f_frsize=1)
        with patch.object(health.os, 'statvfs', space):
            result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        self.assertEqual('failed', result['result'])
        self.assertEqual('verification', result['phase'])
        self.assertIsNone(self.status()['state']['last_confirmed_attempt_id'])

    def test_cleanup_failure_keeps_acceptance_and_only_warns_locally(self):
        original = Path.unlink
        def deny_copy_unlink(path, *args, **kwargs):
            if path.name == 'archive.sqlite':
                raise PermissionError('synthetic filesystem refusal')
            return original(path, *args, **kwargs)
        with patch.object(Path, 'unlink', deny_copy_unlink):
            result = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('manual')
        self.assertEqual('accepted', result['result'])
        status = self.status(result['attempt_id'])
        self.assertEqual([], status['open_incidents'])
        self.assertIsNone(status['state']['active_attempt_id'])
        self.assertTrue((self.data / result['attempt_id'] / 'archive.sqlite').exists())
        self.assertIn('cleanup', (self.logs / 'run.log').read_text())
        health.ArchiveHealthRunner(self.limits, self.runtime, self.transport).run('scheduled')
        self.assertEqual(1, self.status()['state']['last_attempt_sequence'])

    def test_foreign_acknowledgements_preserve_evidence_even_after_remote_acceptance(self):
        result = health.ArchiveHealthRunner(self.limits, self.runtime, self.peer('foreign-acknowledgements')).run('manual')
        self.assertEqual('uncertain', result['result'])
        self.assertTrue((self.data / result['attempt_id'] / 'archive.sqlite').exists())
        self.assertEqual('closed', self.status(result['attempt_id'])['attempt']['status'])
        self.assertEqual(['status', 'export', 'result', 'status', 'result'],
                         json.loads((self.root / 'peer-requests.json').read_text()))

    def test_result_timeout_exhausts_one_total_budget_without_more_calls(self):
        result = health.ArchiveHealthRunner(replace(self.limits, result_seconds=.15), self.runtime,
                                           self.peer('result-budget')).run('manual')
        self.assertEqual('uncertain', result['result'])
        self.assertEqual(['status', 'export', 'result'], json.loads((self.root / 'peer-requests.json').read_text()))
        self.assertTrue((self.data / result['attempt_id'] / 'archive.sqlite').exists())
        self.assertEqual(result['attempt_id'], self.status()['state']['active_attempt_id'])

    def test_stale_handoff_and_active_local_lock_never_start_a_new_attempt(self):
        handoff = dict(health.requester_identity(os.getpid()), schema_version=1, attempt_id='a' * 32,
                       start_kind='manual', issued=time.monotonic(), state='pending')
        handoff['start_ticks'] = '0'
        file = self.data / '.start-request.json'
        file.write_text(json.dumps(handoff))
        file.chmod(0o600)
        runner = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport)
        self.assertEqual('failed', health.start_run(runner, 'scheduled')['result'])
        self.assertEqual(handoff, json.loads(file.read_text()))
        self.assertEqual(0, self.status()['state']['last_attempt_sequence'])
        file.unlink()  # Explicit fixture operator reconciliation, never done by the runner.
        with health.private_lock(self.data / '.run.lock'):
            result = runner.run('manual')
        self.assertEqual('failed', result['result'])
        self.assertEqual(0, self.status()['state']['last_attempt_sequence'])
        result = health.start_run(runner, 'scheduled')
        self.assertEqual('accepted', result['result'])
        self.assertEqual('scheduled', self.status(result['attempt_id'])['attempt']['start_kind'])

    def test_timer_claims_the_run_before_a_racing_manual_dispatch_can_leave_a_handoff(self):
        runner = health.ArchiveHealthRunner(self.limits, self.runtime, self.transport)
        group_file = self.runtime.proc / 'self/cgroup'
        original_close = os.close
        competing = []
        def race_at_dispatch_unlock(descriptor):
            try:
                dispatch = os.readlink(f'/proc/self/fd/{descriptor}').endswith('/.dispatch.lock')
            except OSError:
                dispatch = False
            original_close(descriptor)
            if dispatch and not competing:
                competing.append(None)
                group_file.write_text('0::/user.slice/example.scope\n')
                competing[0] = health.start_run(runner, 'manual')
                group_file.write_text('0::' + health.GROUP + '\n')
        with patch.object(health.os, 'close', race_at_dispatch_unlock), \
                patch.object(health.subprocess, 'run', return_value=SimpleNamespace(returncode=1)) as start:
            result = health.start_run(runner, 'scheduled')
        self.assertEqual('accepted', result['result'])
        self.assertEqual('uncertain', competing[0]['result'])
        start.assert_not_called()
        self.assertFalse((self.data / '.start-request.json').exists())
        self.assertEqual(1, self.status()['state']['last_attempt_sequence'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
