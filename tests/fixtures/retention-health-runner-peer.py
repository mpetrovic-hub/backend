#!/usr/bin/env python3
"""A local wire-fault peer around the real PHP transport, using artificial data only."""
import json
import os
from pathlib import Path
import subprocess
import sqlite3
import sys
import time

mode, fixture, database, archive, action = sys.argv[1:]
request = sys.stdin.buffer.read(16385)
root = Path(archive).parent
history = root / 'peer-requests.json'
calls = json.loads(history.read_text()) if history.exists() else []
calls.append(action)
history.write_text(json.dumps(calls))
if action == 'fail' and mode == 'changed-byte-fail-unavailable':
    sys.exit(255)
if action == 'result' and mode in ('retry-result', 'uncertain-result'):
    if mode == 'uncertain-result' or calls.count('result') == 1:
        sys.exit(255)
if action == 'result' and mode == 'result-budget':
    time.sleep(20)
if action == 'export' and mode in ('target-grew', 'lost-target-rejection'):
    with sqlite3.connect(archive) as db:
        db.execute('CREATE TABLE grew_before_export (id INTEGER PRIMARY KEY)')
response = subprocess.run(['php', fixture, '--transport', database, archive], input=request,
                          capture_output=True, env=dict(os.environ, SSH_ORIGINAL_COMMAND=action))
wire, exit_code = response.stdout, response.returncode
if action == 'export' and mode == 'lost-target-rejection':
    sys.exit(255)
if action == 'status' and mode == 'malformed-status':
    value = json.loads(wire)
    value['state'] = []
    wire = json.dumps(value).encode() + b'\n'
if action == 'status' and mode == 'above-old-size-ceiling':
    value = json.loads(wire)
    value['target']['bytes'] = 6600000000
    wire = json.dumps(value).encode() + b'\n'
if mode == 'foreign-acknowledgements' and action in ('result', 'status'):
    value = json.loads(wire)
    if action == 'result':
        value['result_fingerprint'] = 'f' * 64
    elif value.get('attempt'):
        value['attempt']['sha256'] = 'f' * 64
    wire = json.dumps(value).encode() + b'\n'
if action == 'fail' and mode == 'changed-byte-malformed-fail':
    value = json.loads(wire)
    value['attempt']['expected_bytes'] = float(value['attempt']['expected_bytes'])
    wire = json.dumps(value).encode() + b'\n'
if action == 'result' and mode == 'lost-result-ack' and calls.count('result') == 1:
    sys.exit(255)
if action == 'export' and response.returncode == 0:
    end = wire.index(b'\n') + 1
    size = json.loads(wire[:end])['bytes']
    if mode in ('changed-byte', 'changed-byte-fail-unavailable', 'changed-byte-malformed-fail'):
        data = bytearray(wire)
        data[end + 100] ^= 1
        wire = bytes(data)
    elif mode == 'missing-trailer':
        wire = wire[:end + size]
    elif mode == 'truncated-header':
        wire = wire[:end - 1]
    elif mode == 'truncated-bytes':
        wire = wire[:end + size - 1]
    elif mode == 'extra-output':
        wire += b'unexpected\n'
    elif mode == 'wrong-size':
        header = json.loads(wire[:end])
        header['bytes'] += 1
        wire = json.dumps(header).encode() + b'\n' + wire[end:]
    elif mode == 'duplicate-header':
        wire = wire[:end - 2] + b',"schema_version":1}\n' + wire[end:]
    elif mode == 'wrong-trailer-id':
        trailer = json.loads(wire[end + size:])
        trailer['attempt_id'] = 'f' * 32
        wire = wire[:end + size] + json.dumps(trailer).encode() + b'\n'
    elif mode == 'nonzero-export':
        exit_code = 255
    elif mode == 'stalled-export':
        child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(20)'])
        (root / 'peer-child.pid').write_text(str(child.pid))
        sys.stdout.buffer.write(wire[:end + 5])
        sys.stdout.buffer.flush()
        time.sleep(20)
sys.stdout.buffer.write(wire)
sys.exit(exit_code)
