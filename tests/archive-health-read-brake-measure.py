#!/usr/bin/env python3
"""Measure a complete local archive copy; never query or print its row data."""
import argparse
import base64
from collections import deque
import ctypes
import hashlib
import json
import mmap
import os
from pathlib import Path
import shutil
import subprocess
import time
import uuid

REPO = Path(__file__).resolve().parent.parent
LIBC = ctypes.CDLL(None, use_errno=True)


def sha256(path):
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest() if hasattr(hashlib, 'file_digest') else streamed_hash(source)


def streamed_hash(source):
    value = hashlib.sha256()
    for part in iter(lambda: source.read(1024*1024), b''):
        value.update(part)
    return value.hexdigest()


def residency(path):
    with path.open('rb') as source:
        with mmap.mmap(source.fileno(), 0, access=mmap.ACCESS_COPY) as mapping:
            pages = (ctypes.c_ubyte * ((len(mapping)+4095)//4096))()
            address = ctypes.addressof(ctypes.c_char.from_buffer(mapping))
            if LIBC.mincore(ctypes.c_void_p(address), ctypes.c_size_t(len(mapping)), pages):
                raise OSError(ctypes.get_errno(), 'mincore')
            return sum(bool(value & 1) for value in pages)


def counters():
    operations = read_bytes = write_bytes = 0
    for line in Path('/sys/fs/cgroup/io.stat').read_text().splitlines():
        fields = dict(item.split('=') for item in line.split()[1:])
        operations += int(fields.get('rios', 0)) + int(fields.get('wios', 0))
        read_bytes += int(fields.get('rbytes', 0))
        write_bytes += int(fields.get('wbytes', 0))
    return operations, read_bytes, write_bytes


def conservative_upper(history, now, values, previous):
    history.append((now, values))
    # Timestamps precede counter reads. Keep one older sample: its read
    # completed before the next timestamp, which must be <= previous-1.
    # This bounds every rolling second without assuming atomic/instant reads.
    while len(history)>2 and history[2][0] <= previous-1:
        history.popleft()
    return values[0]-history[0][1][0]


def run(args):
    archive = args.archive.resolve(strict=True)
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    preceding = output/'rate-700-result.json'
    if args.rate == 350 and preceding.is_file():
        previous_result = json.loads(preceding.read_text())
        assert previous_result['conservative_peak_block_iops'] < 1000, 'Previous 700 IOPS gate failed; 350 child not started'
    assert archive.name.startswith('kiwi_retention_archive_') and archive.suffix == '.sqlite'
    assert args.archive.absolute() == archive and not args.archive.is_symlink()
    before_hash = sha256(archive)
    assert before_hash == args.expected_sha256.lower(), 'Archive snapshot differs'
    cache_before = residency(archive)
    with archive.open('rb') as source:
        os.fsync(source.fileno())
        os.posix_fadvise(source.fileno(), 0, 0, os.POSIX_FADV_DONTNEED)
    cache_after = residency(archive)
    assert cache_after == 0, 'Cold-file eviction incomplete'
    php = shutil.which('php')
    assert php
    environment = None
    if args.rate == 700:
        command = [php, str(REPO/'tests/fixtures/archive-health-brake-measure.php'), str(archive), '700']
    else:
        helper = REPO/'tools/database/class-retention-archive-health-read-brake.php'
        environment = json.loads(subprocess.check_output([php, '-r', 'require $argv[1];echo json_encode(Kiwi_Retention_Archive_Health_Read_Brake::child_environment($argv[2],350));', str(helper), str(archive)], text=True))
        payload = {'archive_path': str(archive), 'check': 'quick', 'readiness_path': str(archive.parent/('.kiwi_retention_health_child_'+uuid.uuid4().hex+'.ready')), 'health_read_units_per_second':350, 'corruption_handoff_timeout_seconds':7200}
        command = [php, str(REPO/'tools/database/kiwi-retention-archive-health.php'), '--kiwi-retention-health-child', base64.b64encode(json.dumps(payload).encode()).decode()]
    started = time.monotonic()
    samples = []
    history = deque()
    conservative_peak = after_hour_peak = maximum_gap = 0
    previous = 0
    last_update = -60
    process = None
    process_started = process_finished = None
    supervision_budget_seconds = 7200
    budget_expired_while_running = False
    baseline = final = None
    label = 'rate-'+str(args.rate)
    curve = output/(label+'-io.csv')
    with curve.open('w',buffering=65536) as log:
        log.write('elapsed_seconds,total_block_operations,total_read_bytes,total_write_bytes\n')
        while True:
            now = time.monotonic()-started
            values = counters()
            samples.append((now,values))
            log.write(f'{now:.9f},{values[0]},{values[1]},{values[2]}\n')
            upper = conservative_upper(history, now, values, previous)
            conservative_peak = max(conservative_peak,upper)
            if process_started is not None and now-process_started >= 3600:
                after_hour_peak = max(after_hour_peak,upper)
            maximum_gap = max(maximum_gap,now-previous)
            previous = now
            if process is None and now >= 1.1:
                baseline = values
                process_started = now
                process = subprocess.Popen(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=environment)
            if process is not None and process.poll() is not None and process_finished is None:
                process_finished = now
                final = values
            if process_started is not None and process_finished is None and now-process_started >= supervision_budget_seconds:
                # Preserve the actual child and its OS lock; an exceeded budget is a failed test gate.
                budget_expired_while_running = True
            if process_started is not None and process_finished is None and now-last_update >= 60:
                (output/(label+'-progress.json')).write_text(json.dumps({'elapsed_seconds':now-process_started,'conservative_peak_block_iops':conservative_peak,'running':True})+'\n')
                last_update=now
            if process_finished is not None and now-process_finished >= 1.1:
                break
            time.sleep(.02)
    stdout,stderr=process.communicate()
    result = json.loads(stdout)
    duration = process_finished-process_started
    after_hash = sha256(archive)
    summary = {'rate':args.rate,'mode':'regular_supervisor' if args.rate==700 else 'private_actual_child','supervision_budget_seconds':supervision_budget_seconds,'budget_expired_while_running':budget_expired_while_running,'child_terminated':False,'archive_bytes':archive.stat().st_size,'before_sha256':before_hash,'after_sha256':after_hash,'cold_resident_pages':cache_after,'resident_pages_before_eviction':cache_before,'duration_seconds':duration,'exit_code':process.returncode,'outcome':result,'conservative_peak_block_iops':conservative_peak,'peak_after_minute_60':after_hour_peak,'maximum_sample_gap_seconds':maximum_gap,'sample_count':len(samples),'measured_block_read_bytes':final[1]-baseline[1],'measured_block_write_bytes':final[2]-baseline[2],'curve_sha256':sha256(curve),'counter_scope':'all devices/processes in the local root cgroup; not Hostinger account IOPS'}
    (output/(label+'-result.json')).write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary),flush=True)
    assert process.returncode==0 and not stderr, 'Technical child failure'
    assert not budget_expired_while_running, '7200-second test supervision budget exceeded; child was not killed'
    outcome=result['outcome'] if args.rate==700 else result
    assert outcome['result']=='ok' and outcome['check_completed'], 'Full check did not pass'
    assert before_hash==after_hash and cache_after==0
    assert summary['measured_block_read_bytes'] >= archive.stat().st_size*.9, 'Cold read not supported by block counters'
    assert conservative_peak < 1000, 'Physical IOPS implementation gate failed'
    if args.rate==350: assert duration>3600 and after_hour_peak>0, 'Actual full scan did not run beyond minute 60'
    (output/(label+'-progress.json')).write_text(json.dumps({'elapsed_seconds':duration,'conservative_peak_block_iops':conservative_peak,'running':False})+'\n')


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive',type=Path,required=True)
    parser.add_argument('--expected-sha256',required=True)
    parser.add_argument('--rate',type=int,choices=[700,350],required=True)
    parser.add_argument('--output',type=Path,required=True)
    run(parser.parse_args())
