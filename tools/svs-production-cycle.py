#!/usr/bin/env python3
"""Root-only one-way run: private snapshot -> rehearsal -> permitted PROD merge."""
import datetime
import fcntl
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys
import tarfile
import time

ROOT = pathlib.Path('/root/clubunio-svs-production')
CODE = ROOT / 'code'
PHP = '/RunCloud/Packages/php83rc/bin/php'
MIGRATION = '2026_11_22_020000_svs_historical_import'

def sha(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for b in iter(lambda: f.read(1048576), b''): h.update(b)
    return h.hexdigest()

def write(path, value):
    path.write_text(json.dumps(value, indent=2)+'\n')
    path.chmod(0o600)

def command(args, **kw):
    r = subprocess.run(args, capture_output=True, **kw)
    if r.returncode:
        # Never relay arbitrary SQL/provider errors or private row data.
        raise RuntimeError('Command failed: '+pathlib.Path(args[0]).name)
    return r.stdout

def sql(db, query):
    if db != 'clubunio_prod' and not re.fullmatch(r'clubunio_(?:stage_svs_candidate|svs_rehearsal)_[a-f0-9]{8}', db):
        raise RuntimeError('Database outside import scope')
    return command(['runuser','-u','postgres','--','psql','-X','-v','ON_ERROR_STOP=1','-At','-d',db], input=query.encode()).decode().strip()

def php(tool, args, directory, output=None, permit=None):
    env=os.environ.copy(); env['CLUBUNIO_SVS_SNAPSHOT_DIR']=str(directory/'source')
    env.pop('CLUBUNIO_SVS_PRODUCTION_PERMIT',None)
    if permit: env['CLUBUNIO_SVS_PRODUCTION_PERMIT']=str(permit)
    r=subprocess.run([PHP,str(CODE/'tools'/tool),*args],cwd=CODE,env=env,capture_output=True)
    if output:
        output.write_bytes(r.stdout);output.chmod(0o600)
    if r.returncode:
        # Tool stderr is deliberately redacted by the PHP runner.
        (directory/(tool+'.error.txt')).write_bytes(r.stderr)
        (directory/(tool+'.error.txt')).chmod(0o600)
        raise RuntimeError('Import stopped at '+tool+'; private diagnostic retained')
    return r.stdout

def head():
    exists=sql('clubunio_prod',"select to_regclass('migration_control.svs_runs') is not null")=='t'
    return int(sql('clubunio_prod','select coalesce(max(id),0) from migration_control.svs_runs')) if exists else 0

def run(run_id):
    if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{32}',run_id): raise RuntimeError('Root and opaque run ID required')
    os.umask(0o077)
    lock=open(ROOT/'cycle.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    release=json.loads((ROOT/'release-files.json').read_text())
    for relative, expected in release.items():
        if not sha(CODE/relative)==expected: raise RuntimeError('Frozen importer release changed')
    directory=ROOT/'runs'/run_id;directory.mkdir(mode=0o700)
    archive=ROOT/'incoming'/(run_id+'.tar.gz')
    with tarfile.open(archive) as tar:
        for member in tar.getmembers():
            if member.issym() or member.islnk() or not (member.name=='source' or member.name.startswith('source/')):
                raise RuntimeError('Unexpected snapshot archive member')
        tar.extractall(directory,filter='data')
    manifest=json.loads((directory/'source/manifest.json').read_text())
    age=time.time()-datetime.datetime.fromisoformat(manifest['completed_utc']).timestamp()
    if manifest['source']!='db_acdf8d_mysvs' or manifest['status']!='complete' or not 0<=age<=10800:
        raise RuntimeError('Fresh exact production source required')
    snapshot=sha(directory/'source/manifest.json')
    previous=head()
    original_admin=sql('clubunio_prod',"select md5(string_agg(row_to_json(a)::text,'' order by a.account_id)) from clubunio.platform_admins a")
    with open(directory/'prod-before.dump','wb') as f:
        r=subprocess.run(['runuser','-u','postgres','--','pg_dump','-Fc','--no-owner','clubunio_prod'],stdout=f,stderr=subprocess.PIPE)
        if r.returncode: raise RuntimeError('PROD backup failed')
    suffix=run_id[:8];candidate='clubunio_stage_svs_candidate_'+suffix;rehearsal='clubunio_svs_rehearsal_'+suffix
    command(['runuser','-u','postgres','--','createdb','-O','userunio',candidate])
    command(['runuser','-u','postgres','--','createdb','-O','userunio',rehearsal])
    with open(directory/'prod-before.dump','rb') as f:
        r=subprocess.run(['runuser','-u','postgres','--','pg_restore','--exit-on-error','--role=userunio','--no-owner','--no-privileges','-d',rehearsal],stdin=f,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode: raise RuntimeError('Backup restore failed')
    php('build-svs-import-candidate.php',[candidate,str(directory/'source'),snapshot],directory,directory/'build.json')
    if sql(rehearsal,"select count(*) from clubunio.migrations where migration='"+MIGRATION+"'")=='0':
        php('prepare-svs-history-rehearsal.php',[rehearsal],directory)
    args=['--apply',candidate,rehearsal,snapshot]
    php('run-svs-delta-import.php',args,directory,directory/'rehearsal.json')
    php('run-svs-delta-import.php',args,directory,directory/'repeat.json')
    result=json.loads((directory/'rehearsal.json').read_text());repeat=json.loads((directory/'repeat.json').read_text())
    if result['conflicts'] or repeat['inserted'] or repeat['updated']: raise RuntimeError('Rehearsal did not converge')
    if not (ROOT/'live-enabled.json').exists():
        print(json.dumps({'status':'rehearsed','run_id':run_id,'inserted':result['inserted'],'updated':result['updated'],'repeat_writes':repeat['inserted']+repeat['updated'],'source_manifest_sha256':snapshot}))
        return
    active=json.loads((ROOT/'live-enabled.json').read_text())
    if active['release_sha256']!=sha(ROOT/'release-files.json'): raise RuntimeError('Release has not been enabled for PROD')
    runtime=json.loads((ROOT/'runtime-files.json').read_text())
    for relative, expected in runtime.items():
        if sha(pathlib.Path('/home/runcloud/webapps/ClubUnioProd')/relative)!=expected: raise RuntimeError('PROD history runtime changed; review required')
    permit={'target':'clubunio_prod','tenant':'sv-sissach','direction':'phpmaker_prod_to_clubunio_prod','import_secrets':False,
            'snapshot_sha256':snapshot,'backup_sha256':sha(directory/'prod-before.dump'),
            'rehearsal_sha256':sha(directory/'rehearsal.json'),'repeat_sha256':sha(directory/'repeat.json'),
            'expires_at':int(time.time())+3600,'previous_run_id':previous}
    write(directory/'permit.json',permit)
    php('prepare-svs-production-schema.php',['clubunio_prod',snapshot],directory,permit=directory/'permit.json')
    # Rollback-only PROD preview still checks every current field and locks real rows.
    php('run-svs-delta-import.php',['--rehearse',candidate,'clubunio_prod',snapshot],directory,directory/'prod-preview.json',directory/'permit.json')
    preview=json.loads((directory/'prod-preview.json').read_text())
    if preview['conflicts']: raise RuntimeError('PROD preview conflicted')
    php('run-svs-delta-import.php',['--apply',candidate,'clubunio_prod',snapshot],directory,directory/'prod-apply.json',directory/'permit.json')
    applied=json.loads((directory/'prod-apply.json').read_text())
    current_admin=sql('clubunio_prod',"select md5(string_agg(row_to_json(a)::text,'' order by a.account_id)) from clubunio.platform_admins a")
    if current_admin!=original_admin: raise RuntimeError('Platform administration changed unexpectedly')
    # Deliberately no source write, account activation, invitation, provider job or token import.
    summary={'status':'applied','run_id':run_id,'source_manifest_sha256':snapshot,'backup_sha256':permit['backup_sha256'],
             'direction':permit['direction'],'mapping_version':applied['mapping_version'],'previous_run_id':previous,'current_run_id':head(),
             'inserted':applied['inserted'],'updated':applied['updated'],'unchanged':applied['unchanged'],
             'missing_review':applied['missing_review'],'conflicts':applied['conflicts'],'platform_admin_unchanged':True,
             'source_tables':len(manifest['tables']),'source_rows':sum(t['rows'] for t in manifest['tables'].values()),
             'completed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
    write(directory/'result.json',summary);write(ROOT/'last-success.json',summary)
    # Permit is single-baseline-use and is removed after success.
    (directory/'permit.json').unlink()
    # These two databases were created solely for this run; retain dumps/reports,
    # not an ever-growing pair of full temporary databases every twelve hours.
    pending=[]
    for temporary in [candidate,rehearsal]:
        try:command(['runuser','-u','postgres','--','dropdb',temporary])
        except RuntimeError:pending.append(temporary)
    if pending:
        summary['temporary_cleanup_pending']=len(pending)
        write(directory/'result.json',summary);write(ROOT/'last-success.json',summary)
    print(json.dumps(summary))

if __name__=='__main__':
    try:
        if len(sys.argv)!=2: raise RuntimeError('One run ID required')
        run(sys.argv[1])
    except Exception as e:
        print(json.dumps({'status':'stopped','reason':str(e) if isinstance(e,RuntimeError) else type(e).__name__}),file=sys.stderr)
        sys.exit(1)
