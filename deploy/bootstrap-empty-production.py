"""Root-only VPS bootstrap: schema + migration metadata, never tenant data."""
import argparse
import datetime
import hashlib
import os
from pathlib import Path
import re
import subprocess

SOURCE = 'clubunio_stage'
TARGET = 'clubunio_prod'


def run(args, data=None):
    result = subprocess.run(['sudo', '-u', 'postgres', *args], input=data,
                            text=True, capture_output=True, check=True)
    return result.stdout


def sql(db, query):
    return run(['psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1', '-d', db, '-c', query]).strip()


def dump(db, *options):
    return run(['pg_dump', '-d', db, '--no-owner', '--no-privileges', *options])


def normalized(content):
    return '\n'.join(line for line in content.splitlines()
                     if not line.startswith(('\\restrict ', '\\unrestrict ', '--'))).strip()


def assert_empty():
    assert sql(TARGET, 'select current_database()') == TARGET
    assert sql(TARGET, "select pg_get_userbyid(datdba) from pg_database where datname=current_database()") == 'userunio'
    assert sql(TARGET, "select count(*) from pg_namespace where nspname not like 'pg_%' and nspname not in ('public','information_schema')") == '0', 'Target has application schemas'
    assert sql(TARGET, "select count(*) from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public'") == '0', 'Target public schema is not empty'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['plan', 'rehearse', 'apply'])
    parser.add_argument('--target', required=True, choices=[TARGET])
    args = parser.parse_args()
    assert os.geteuid() == 0, 'Run on the VPS as root'
    assert_empty()
    assert Path('/home/runcloud/webapps/ClubUnioProd/storage/framework/down').exists(), 'Maintenance required'
    schema = dump(SOURCE, '--schema-only', '--schema=clubunio')
    assert 'legacy_prod.' not in schema and 'migration_audit.' not in schema, 'External schema dependency'
    expected = sorted(p.stem for p in (Path(__file__).resolve().parent.parent / 'database/clubunio').glob('*.php'))
    actual = sql(SOURCE, 'select migration from clubunio.migrations order by migration').splitlines()
    assert actual == expected, 'Source migration journal does not match this code release'
    journal = dump(SOURCE, '--data-only', '--table=clubunio.migrations')
    assert normalized(schema) == normalized(dump(SOURCE, '--schema-only', '--schema=clubunio')), 'Source schema changed'
    digest = hashlib.sha256(normalized(schema).encode()).hexdigest()
    print(f'Source={SOURCE}; target={TARGET}; migrations={len(actual)}; schema_sha256={digest}')
    if args.mode == 'plan':
        print('Plan only; no target writes.')
        return
    backup = Path('/root/clubunio-prod-backups') / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700)
    os.chmod(backup.parent, 0o700)
    (backup / 'before.sql').write_text(dump(TARGET))
    (backup / 'schema.sql').write_text(schema)
    (backup / 'migration-journal.sql').write_text(journal)
    # Explicit transaction: any SQL/assertion error aborts the entire target build.
    verify = """
DO $verify$
DECLARE t record; n bigint;
BEGIN
 FOR t IN SELECT tablename FROM pg_tables WHERE schemaname='clubunio' AND tablename<>'migrations' LOOP
  EXECUTE format('SELECT count(*) FROM clubunio.%I', t.tablename) INTO n;
  IF n<>0 THEN RAISE EXCEPTION 'Business table % is not empty',t.tablename; END IF;
 END LOOP;
END $verify$;
"""
    content = 'BEGIN; SET ROLE userunio;\n' + schema + journal + verify
    content += '\nCOMMIT;\n' if args.mode == 'apply' else '\nROLLBACK;\n'
    run(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-d', TARGET], content)
    if args.mode == 'rehearse':
        assert_empty()
        print('Rehearsal passed; transaction rolled back; target remains empty.')
    else:
        assert normalized(schema) == normalized(dump(TARGET, '--schema-only', '--schema=clubunio')), 'Schema comparison failed'
        assert sql(TARGET, 'select migration from clubunio.migrations order by migration').splitlines() == actual
        print('Committed; schema and migration journal independently verified; business tables empty.')
    print(f'Private recovery artifacts: {backup}')


if __name__ == '__main__':
    main()
