#!/usr/bin/env python3
"""CLI gates against prepared isolated fixtures; never changes the live database."""
import argparse
import hashlib
import json
import os
import pathlib
import re
import subprocess

p = argparse.ArgumentParser()
p.add_argument('--lab-root', required=True)
p.add_argument('--source', required=True)
p.add_argument('--stale-source', required=True)
p.add_argument('--drift-target', required=True, help='Disposable target whose amount precision is intentionally changed')
p.add_argument('--target', required=True)
a = p.parse_args()
if os.geteuid() != 0:
    raise SystemExit('Root-owned isolated lab required')
for name in [a.source, a.stale_source]:
    if not re.fullmatch(r'clubunio_stage_svs_candidate_[a-f0-9]{8}', name):
        raise SystemExit('Isolated source required')
for name in [a.target, a.drift_target]:
    if not re.fullmatch(r'clubunio_svs_rehearsal_[a-f0-9]{8}', name):
        raise SystemExit('Isolated target required')
if a.target == a.drift_target or a.source == a.stale_source:
    raise SystemExit('Separate negative fixtures required')
lab = pathlib.Path(a.lab_root).resolve()
if not str(lab).startswith('/root/clubunio-'):
    raise SystemExit('Private lab required')
code = lab / 'code'
php = '/RunCloud/Packages/php83rc/bin/php'
sha = hashlib.sha256((lab / 'source/manifest.json').read_bytes()).hexdigest()
passed = {}

def reject(label, script, arguments, message):
    r = subprocess.run([php, str(code / 'tools' / script), *arguments], cwd=code, capture_output=True, text=True)
    if r.returncode == 0 or message not in r.stderr:
        raise RuntimeError('Gate failed: ' + label)
    passed[label] = True

reject('live_merge', 'run-svs-delta-import.php', ['--apply', a.source, 'clubunio_prod', sha], 'live PROD is forbidden')
reject('live_builder', 'build-svs-import-candidate.php', ['clubunio_prod', str(lab / 'source'), sha], 'Candidate failed at guard')
reject('live_schema_preparation', 'prepare-svs-history-rehearsal.php', ['clubunio_prod'], 'Rehearsal only')
reject('stale_projection', 'run-svs-delta-import.php', ['--apply', a.stale_source, a.target, sha], 'Stale or unverified source projection')
# Only this explicitly named throwaway fixture is mutated, never the main accepted copy.
sql = 'ALTER TABLE clubunio.expense_lines ALTER COLUMN amount TYPE numeric(24,4);'
r = subprocess.run(['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1', '-d', a.drift_target], input=sql, capture_output=True, text=True)
if r.returncode != 0:
    raise RuntimeError('Schema fixture preparation failed; details suppressed')
reject('numeric_schema_drift', 'run-svs-delta-import.php', ['--apply', a.source, a.drift_target, sha], 'Target schema changed')
print(json.dumps({'status': 'passed', 'gates': passed, 'live_writes': False}, indent=2))
