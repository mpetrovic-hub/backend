#!/usr/bin/env python3
"""Recompute preserved numeric curve hashes/windows; never open an archive."""
from collections import deque
import csv,gzip,hashlib,json,runpy
from pathlib import Path

root=Path(__file__).resolve().parent
repo=root.parents[2]
upper=runpy.run_path(str(repo/'tests/archive-health-read-brake-measure.py'))['conservative_upper']
for name in ('rejected-noise','cold-700','cold-350'):
    result=json.loads((root/(name+'-result.json')).read_text())
    curve=root/result['curve']
    assert curve.name==name+'-io.csv.gz' and curve.parent==root
    assert hashlib.sha256(curve.read_bytes()).hexdigest()==result['compressed_curve_sha256']
    digest=hashlib.sha256()
    with gzip.open(curve,'rb') as source:
        for block in iter(lambda:source.read(65536),b''):digest.update(block)
    assert digest.hexdigest()==result['uncompressed_curve_sha256']
    history=deque();previous=0;previous_values=None;peak=after_hour=count=0;started=None
    with gzip.open(curve,'rt',newline='') as source:
        for row in csv.DictReader(source):
            now=float(row['elapsed_seconds'])
            values=tuple(int(row[key]) for key in ('total_block_operations','total_read_bytes','total_write_bytes'))
            assert now>=previous
            if previous_values is not None:assert all(value>=old for value,old in zip(values,previous_values))
            value=upper(history,now,values,previous);peak=max(peak,value)
            if started is None and now>=1.1:started=now
            if started is not None and now-started>=3600:after_hour=max(after_hour,value)
            previous=now;previous_values=values;count+=1
    assert peak==result['certified_conservative_peak_block_iops']
    assert after_hour==result['certified_peak_after_minute_60'] and count==result['sample_count']
    assert result['before_sha256']==result['after_sha256'] and result['cold_resident_pages']==0
    assert result['full_check_completed'] and result['child_exit_code']==0
    if result['gate_status']=='PASS':
        assert peak<1000 and result['duration_seconds']<result['supervision_budget_seconds']
        if result['rate']==350:assert result['duration_seconds']>3600 and after_hour>0
    else:assert peak>=1000
    print(f"PASS preserved {name}: certified peak {peak}, after-hour peak {after_hour}, {count} samples, unchanged curve hashes")