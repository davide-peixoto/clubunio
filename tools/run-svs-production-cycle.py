#!/usr/bin/env python3
"""Windows agent entry point. Reads PHPMaker only; writes solely to ClubUnio via gated VPS runner."""
import argparse
import json
import os
import pathlib
import shutil
import subprocess
import tarfile
import uuid

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-id',default=uuid.uuid4().hex);a=p.parse_args()
    if len(a.run_id)!=32 or any(c not in '0123456789abcdef' for c in a.run_id):raise RuntimeError('Opaque run ID required')
    root=pathlib.Path(__file__).resolve().parents[1]
    private=pathlib.Path('C:/ClubUnio/storage/app/private/prod-migration/production-runs');private.mkdir(exist_ok=True)
    run=private/a.run_id;run.mkdir();source=run/'source'
    env=os.environ.copy();env['CLUBUNIO_TOOL_AUTOLOAD']='C:/ClubUnio/vendor/autoload.php'
    r=subprocess.run([shutil.which('php') or 'php',str(root/'tools/export-svs-production-snapshot.php'),'--output-dir',str(source)],env=env,capture_output=True)
    if r.returncode:raise RuntimeError('Read-only PHPMaker snapshot failed; no target writes performed')
    archive=run/'source.tar.gz'
    with tarfile.open(archive,'w:gz') as tar:tar.add(source,arcname='source')
    host='root@vps.clubunio.com'
    r=subprocess.run(['scp','-o','BatchMode=yes',str(archive),host+':/root/clubunio-svs-production/incoming/'+a.run_id+'.tar.gz'],capture_output=True)
    if r.returncode:raise RuntimeError('Private snapshot upload failed')
    r=subprocess.run(['ssh','-o','BatchMode=yes',host,'python3 /root/clubunio-svs-production/code/tools/svs-production-cycle.py '+a.run_id],capture_output=True)
    (run/'result.json').write_bytes(r.stdout);(run/'diagnostic.txt').write_bytes(r.stderr)
    if r.returncode:raise RuntimeError('VPS import stopped; private diagnostic saved at '+str(run))
    summary=json.loads(r.stdout);print(json.dumps(summary))

if __name__=='__main__':
    try:main()
    except Exception as e:
        print(json.dumps({'status':'stopped','reason':str(e) if isinstance(e,RuntimeError) else type(e).__name__}));raise SystemExit(1)
