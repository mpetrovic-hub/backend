#!/usr/bin/env python3
"""Small process-isolated configuration/environment contract checks."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

repo=Path(__file__).resolve().parent.parent
php=shutil.which('php')
config=repo/'includes/core/class-config.php'
helper=repo/'tools/database/class-retention-archive-health-read-brake.php'
code="define('ABSPATH',getcwd().'/');if($argv[2]!=='default'){define('KIWI_RETENTION_ARCHIVE_HEALTH_TIMEOUT_SECONDS',(int)$argv[2]);}require $argv[1];echo (new Kiwi_Config())->get_retention_archive_health_timeout_seconds();"
for value,expected in [('default',7200),('30',30),('600',600),('3600',3600),('7200',7200),('1',30),('99999',7200)]:
    result=subprocess.check_output([php,'-r',code,str(config),value],text=True)
    assert int(result)==expected,(value,result)
print('PASS isolated default/clamps: 7200, 30, 600, 3600, 7200, 30, 7200')

with tempfile.TemporaryDirectory(prefix='kiwi-brake-contracts-') as directory:
    target=Path(directory)/'kiwi_retention_archive_2026.sqlite'
    shutil.copyfile(repo/'tools/database/archive-health-read-brake/fixtures/kiwi_retention_archive_2000.sqlite',target)
    code="require $argv[1];putenv('KIWI_TEST_SENTINEL=preserved');putenv('PHPRC=/private/test-php.ini');$before=getenv();$child=Kiwi_Retention_Archive_Health_Read_Brake::child_environment($argv[2]);echo json_encode(['parent_unchanged'=>$before===getenv(),'sentinel'=>$child['KIWI_TEST_SENTINEL'],'ini'=>$child['PHPRC'],'rate'=>$child['KIWI_HEALTH_READS_PER_SECOND'],'binding'=>$child['LD_BIND_NOW']]);"
    result=json.loads(subprocess.check_output([php,'-r',code,str(helper),str(target)],text=True))
    assert result=={'parent_unchanged':True,'sentinel':'preserved','ini':'/private/test-php.ini','rate':'700','binding':'1'}
    print('PASS parent environment and PHP INI inheritance remain unchanged')
    code="require $argv[1];putenv('LD_PRELOAD=/private/foreign.so');try{Kiwi_Retention_Archive_Health_Read_Brake::child_environment($argv[2]);exit(2);}catch(Throwable $error){echo $error->getMessage();}"
    assert subprocess.check_output([php,'-r',code,str(helper),str(target)],text=True)=='health_brake_configuration_invalid'
    print('PASS foreign preload is rejected without starting a child')
    child_source=(repo/'tools/database/kiwi-retention-archive-health.php').read_text()
    assert "min(7200, max(30, (int) ($payload['corruption_handoff_timeout_seconds'] ?? 7200)))" in child_source
    supervisor_source=(repo/'includes/services/class-retention-archive-check-supervisor.php').read_text()
    assert "'corruption_handoff_timeout_seconds' => $this->config->get_retention_archive_health_timeout_seconds()" in supervisor_source
    print('PASS handoff uses the same default/range without a hidden 3600 cap')

# A counter read may include an I/O occurring after its recorded timestamp.
# Retain the prior sample so that an actual second ending at1.003 includes
# the I/O at0.005 and the two I/Os at0.2, rather than silently losing one.
from collections import deque
import runpy
measurement=runpy.run_path(str(repo/'tests/archive-health-read-brake-measure.py'))
history=deque(); previous=-.02
for now,operations in [(-.02,0),(0.,1),(.02,1),(.2,3),(1.,3),(1.01,3)]:
    upper=measurement['conservative_upper'](history,now,(operations,0,0),previous)
    previous=now
assert upper==3
print('PASS conservative counter-read boundary includes every in-window I/O')
