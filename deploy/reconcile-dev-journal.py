"""Reviewed VPS-only reconciliation against Stage; never replay historical data migrations."""
import argparse
import json
import subprocess
from pathlib import Path

TARGET = 'clubunio_dev'
SOURCE = 'clubunio_stage'
BACKUP = Path('/root/clubunio-consolidation-20261122T203701Z')
TABLES = {
    '2026_11_13_001000_account_security': ['account_security', 'account_passkeys', 'account_security_pairs'],
    '2026_11_13_002000_support_center': ['support_tickets', 'support_posts', 'support_articles'],
    '2026_11_13_002010_support_public_requests': ['support_public_requests'],
    '2026_11_13_002090_support_triage': ['support_triage'],
    '2026_11_13_002200_support_workflow': ['support_attachments'],
    '2026_11_15_002300_support_queue': ['support_jobs'],
    '2026_11_15_002310_support_zammad': ['support_zammad_exports'],
}
TRACKING = '2026_11_10_000100_mobile_membership_tracking'
PHOTOS = '2026_11_13_000600_profile_photos'


def run(args, data=None):
    return subprocess.run(['sudo', '-u', 'postgres', *args], input=data, text=True,
                          capture_output=True, check=True).stdout.strip()


def query(db, sql):
    return run(['psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1', '-d', db, '-c', sql])


def signature(db, table):
    return query(db, f"""select json_build_object(
      'columns',(select json_agg(x order by name) from (select a.attname as name,
        format_type(a.atttypid,a.atttypmod) as type,a.attnotnull as required,
        pg_get_expr(d.adbin,d.adrelid) as def,a.attidentity as identity
        from pg_attribute a join pg_class c on c.oid=a.attrelid
        join pg_namespace n on n.oid=c.relnamespace
        left join pg_attrdef d on d.adrelid=c.oid and d.adnum=a.attnum
        where n.nspname='clubunio' and c.relname='{table}' and a.attnum>0 and not a.attisdropped)x),
      'constraints',(select json_agg(x order by name) from (select conname as name,
        pg_get_constraintdef(k.oid,true) as def from pg_constraint k
        join pg_class c on c.oid=k.conrelid join pg_namespace n on n.oid=c.relnamespace
        where n.nspname='clubunio' and c.relname='{table}')x),
      'indexes',(select json_agg(x order by indexname) from (select indexname,indexdef
        from pg_indexes where schemaname='clubunio' and tablename='{table}')x))""")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    assert (BACKUP / (TARGET + '.dump')).is_file(), 'Verified private backup required'
    expected = set(TABLES) | {TRACKING, PHOTOS}
    source = set(query(SOURCE, 'select migration from clubunio.migrations').splitlines())
    current = set(query(TARGET, 'select migration from clubunio.migrations').splitlines())
    assert source - current == expected, 'Unexpected journal delta; review again'
    for tables in TABLES.values():
        for table in tables:
            assert signature(TARGET, table) == signature(SOURCE, table), table
    tracking = """select json_build_object(
      'columns',(select json_agg(x order by table_name,column_name) from
        (select table_name,column_name,data_type,character_maximum_length,is_nullable
         from information_schema.columns where table_schema='clubunio' and
         ((table_name='mobile_identity_challenges' and column_name='registration_id') or
          (table_name='mobile_sessions' and column_name='security_stamp')))x),
      'constraints',(select json_agg(x order by conname) from
        (select conname,pg_get_constraintdef(oid) as def from pg_constraint where connamespace='clubunio'::regnamespace
         and conname in ('mobile_identity_registration_fk','mobile_identity_registration_purpose'))x),
      'indexes',(select json_agg(x) from (select indexname,indexdef from pg_indexes
        where schemaname='clubunio' and indexname='mobile_identity_registration_owner')x))"""
    assert query(TARGET, tracking) == query(SOURCE, tracking), 'Tracking/security column mismatch'
    photos = ['account_profile_photos', 'member_profile_photos']
    for table in photos:
        assert query(TARGET, f"select to_regclass('clubunio.{table}') is null") == 't'
    ddl = run(['pg_dump', '-d', SOURCE, '--schema-only', '--no-owner', '--no-privileges',
               '--table=clubunio.account_profile_photos', '--table=clubunio.member_profile_photos'])
    sql = "BEGIN; SET LOCAL lock_timeout='5s'; SET LOCAL statement_timeout='60s';\n"
    sql += "DO $$ BEGIN IF current_database()<>'clubunio_dev' THEN RAISE EXCEPTION 'Wrong target'; END IF; END $$;\n"
    sql += "SELECT pg_advisory_xact_lock(hashtext('clubunio-journal-reconciliation'));\n" + ddl + '\n'
    sql += 'GRANT SELECT,INSERT,UPDATE,DELETE ON clubunio.account_profile_photos,clubunio.member_profile_photos TO clubunio_dev_app;\n'
    for name in sorted(expected):
        sql += f"INSERT INTO clubunio.migrations(migration,batch) SELECT '{name}',coalesce(max(batch),0)+1 FROM clubunio.migrations;\n"
    sql += 'COMMIT;\n' if args.apply else 'ROLLBACK;\n'
    (BACKUP / 'dev-journal-reconcile.sql').write_text(sql)
    run(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-d', TARGET], sql)
    if args.apply:
        for table in photos:
            assert signature(TARGET, table) == signature(SOURCE, table), table
        after = set(query(TARGET, 'select migration from clubunio.migrations').splitlines())
        assert after == current | expected
    print(json.dumps({'mode': 'committed' if args.apply else 'rehearsed and rolled back',
                      'schema_additions': photos, 'verified_existing_migrations': 8,
                      'journal_entries_added': len(expected) if args.apply else 0}))


if __name__ == '__main__':
    main()
