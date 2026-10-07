#!/usr/bin/env python3
"""Small, synthetic native ledger tests; the real PDO path is tested separately."""
import ctypes
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import sys
import threading
import time

action = sys.argv[1]
target = os.environ["KIWI_HEALTH_BRAKE_TARGET"]
fixture = os.environ["KIWI_HEALTH_BRAKE_FIXTURE"]
control = sqlite3.connect(":memory:")
for phase, rate in [("fixture_700",700),("fixture_350",350)]:
    assert control.execute("SELECT kiwi_health_brake_control_v1(?,?)",(phase,rate)).fetchone() == (1,)
    probe = sqlite3.connect("file:" + fixture + "?mode=ro&immutable=1", uri=True)
    probe.execute("PRAGMA mmap_size=0")
    probe.execute("PRAGMA query_only=ON")
    assert probe.execute("PRAGMA integrity_check").fetchall() == [("ok",)]
    status = json.loads(probe.execute("SELECT kiwi_health_brake_status_v1()").fetchone()[0])
    assert not status["error"] and status["connection_bound"] and status["units"] >= 1003
    probe.close()
control.execute("SELECT kiwi_health_brake_control_v1('target',700)")
database = sqlite3.connect("file:" + target + "?mode=ro&immutable=1", uri=True)
database.execute("PRAGMA mmap_size=0")
database.execute("PRAGMA query_only=ON")
get_status = lambda: json.loads(database.execute("SELECT kiwi_health_brake_status_v1()").fetchone()[0])
assert get_status()["connection_bound"]
native = ctypes.CDLL(None, use_errno=True)
for name in ["pread","pread64"]:
    function = getattr(native,name)
    function.argtypes = [ctypes.c_int,ctypes.c_void_p,ctypes.c_size_t,ctypes.c_int64]
    function.restype = ctypes.c_ssize_t
native.mmap.argtypes = [ctypes.c_void_p,ctypes.c_size_t,ctypes.c_int,ctypes.c_int,ctypes.c_int,ctypes.c_int64]
native.mmap.restype = ctypes.c_void_p
native.munmap.argtypes = [ctypes.c_void_p,ctypes.c_size_t]
fd = os.open(target,os.O_RDONLY)

if action == "reads":
    buffer = ctypes.create_string_buffer(1024*1024)
    before = get_status()
    started = time.monotonic()
    assert native.pread(fd,buffer,len(buffer),0) == len(buffer)
    elapsed = time.monotonic()-started
    after = get_status()
    assert after["calls"]-before["calls"] == 256 and after["units"]-before["units"] == 256
    assert elapsed >= .98*255/700
    large_hash = hashlib.sha256(buffer.raw).hexdigest()
    before = get_status()
    assert native.pread64(fd,buffer,8192,4094) == 8192
    after = get_status()
    assert after["calls"]-before["calls"] == 3
    unaligned_hash = hashlib.sha256(buffer.raw[:8192]).hexdigest()
    ctypes.set_errno(0)
    assert native.pread(fd,buffer,0,0) == 0
    assert native.pread64(fd,buffer,10,os.stat(target).st_size) == 0
    assert native.pread64(fd,buffer,4096,os.stat(target).st_size-2048) == 2048
    ctypes.set_errno(0)
    assert native.pread(fd,buffer,20,-1) == -1 and ctypes.get_errno() == 22
    # Shared ledger, and idle time cannot accumulate a burst budget.
    time.sleep(.3)
    errors = []
    def reader():
        small = ctypes.create_string_buffer(4096)
        for _ in range(70):
            if native.pread(fd,small,len(small),0) != len(small): errors.append("read")
    started = time.monotonic()
    threads = [threading.Thread(target=reader) for _ in range(2)]
    for thread in threads: thread.start()
    for thread in threads: thread.join()
    assert not errors and time.monotonic()-started >= .98*139/700
    print(json.dumps({"result":"PASS","large_sha256":large_hash,"unaligned_sha256":unaligned_hash,"chunk_calls":256,"unaligned_calls":3}))
elif action == "mmap":
    anonymous = native.mmap(None,4096,3,0x22,-1,0)
    assert anonymous != ctypes.c_void_p(-1).value
    assert native.munmap(anonymous,4096) == 0
    assert native.mmap(None,4096,1,1,fd,0) == ctypes.c_void_p(-1).value
    assert get_status()["error"]
    print(json.dumps({"result":"PASS","mapping_denied":True,"error_sticky":True}))
elif action == "fd_reuse":
    small = ctypes.create_string_buffer(4096)
    assert native.pread(fd,small,len(small),0) == len(small)
    other = os.open(__file__,os.O_RDONLY)
    os.dup2(other,fd)
    assert native.pread(fd,small,len(small),0) == -1, "Reused bound FD must fail before reading unrelated bytes"
    assert get_status()["error"]
    print(json.dumps({"result":"PASS","fd_reuse_denied":True}))
elif action in ["fadvise", "clock", "sleep", "proc_fd"]:
    small = ctypes.create_string_buffer(4096)
    os.environ['KIWI_TEST_FAULT'] = action
    assert native.pread(fd,small,len(small),0) == -1
    os.environ.pop('KIWI_TEST_FAULT')
    assert get_status()['error']
    print(json.dumps({'result':'PASS','fault':action,'error_sticky':True}))
elif action in ['sleep_eintr','pread_eintr','pread_short']:
    small = ctypes.create_string_buffer(4096)
    os.environ['KIWI_TEST_FAULT'] = action
    ctypes.set_errno(0)
    received = native.pread64(fd,small,len(small),0)
    if action == 'pread_eintr':
        assert received == -1 and ctypes.get_errno() == 4
    else:
        assert received == (2048 if action == 'pread_short' else 4096)
    assert not get_status()['error']
    assert native.pread64(fd,small,len(small),0) == len(small)
    print(json.dumps({'result':'PASS','fault':action,'normal_errno_and_retry':True}))
elif action == 'fork_pid':
    pid = os.fork()
    if pid == 0:
        small = ctypes.create_string_buffer(4096)
        os._exit(0 if native.pread(fd,small,len(small),0) == -1 else 2)
    assert os.waitpid(pid,0)[1] == 0 and not get_status()['error']
    print(json.dumps({'result':'PASS','foreign_pid_denied':True}))
else:
    raise AssertionError("Unknown action")
