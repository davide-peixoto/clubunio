\set ON_ERROR_STOP on
BEGIN ISOLATION LEVEL REPEATABLE READ;
SET LOCAL ROLE userunio;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';
DO $$ BEGIN
    IF current_database() <> 'clubunio_stage' OR to_regnamespace('clubunio') IS NULL
       OR (SELECT count(*) FROM clubunio.tenants) <> 1
       OR NOT EXISTS (SELECT 1 FROM clubunio.tenants WHERE slug='sv-sissach')
       OR NOT EXISTS (SELECT 1 FROM migration_audit.run WHERE source_database='db_acdf8d_mysvs' AND status='verified_snapshot') THEN
        RAISE EXCEPTION 'Expected verified SV Sissach Stage';
    END IF;
END $$;
LOCK TABLE clubunio.accounts IN SHARE ROW EXCLUSIVE MODE;
LOCK TABLE clubunio.tenant_accounts IN SHARE MODE;

CREATE TEMP TABLE stage_auth_replay ON COMMIT DROP AS
SELECT a.id,coalesce(u."Password",'') AS password,
       u."sdeletedAt" IS NULL AND coalesce(upper(u."Activated"::text) IN ('1','Y','TRUE'),false)
       AND NOT coalesce(upper(u."Locked"::text) IN ('1','Y','TRUE'),false) AS active
FROM legacy_prod."Users" u
JOIN clubunio.accounts a ON a.username=lower(trim(u."Username"))
JOIN clubunio.tenant_accounts ta ON ta.account_id=a.id AND ta.legacy_user_level=coalesce(u."User_Level"::int,0)
JOIN clubunio.tenants t ON t.id=ta.tenant_id AND t.slug='sv-sissach';

DO $$ DECLARE expected integer; changed integer; BEGIN
    SELECT count(*) INTO expected FROM stage_auth_replay;
    IF expected <> 75 OR expected <> (SELECT count(*) FROM legacy_prod."Users")
       OR expected <> (SELECT count(*) FROM clubunio.accounts)
       OR expected <> (SELECT count(DISTINCT id) FROM stage_auth_replay) THEN
        RAISE EXCEPTION 'Account mapping is incomplete or ambiguous';
    END IF;
    IF EXISTS (SELECT 1 FROM stage_auth_replay WHERE active AND
        (length(password)<>60 OR left(password,4) NOT IN ('$2y$','$2a$','$2b$'))) THEN
        RAISE EXCEPTION 'An enabled source account has a missing or unsupported hash';
    END IF;
    IF EXISTS (SELECT 1 FROM clubunio.accounts WHERE active OR password<>'') THEN
        RAISE EXCEPTION 'Stage accounts are no longer in the original import state';
    END IF;
    UPDATE clubunio.accounts a SET password=r.password,active=r.active,updated_at=CURRENT_TIMESTAMP
    FROM stage_auth_replay r WHERE a.id=r.id;
    GET DIAGNOSTICS changed=ROW_COUNT;
    IF changed<>expected OR EXISTS (SELECT 1 FROM stage_auth_replay r JOIN clubunio.accounts a USING (id)
       WHERE (a.password,a.active) IS DISTINCT FROM (r.password,r.active)) THEN
        RAISE EXCEPTION 'Stage account verification failed';
    END IF;
END $$;
SELECT count(*) AS migrated,count(*) FILTER (WHERE active) AS enabled,
       count(*) FILTER (WHERE NOT active) AS disabled FROM stage_auth_replay;
\if :apply
COMMIT;
\else
ROLLBACK;
\endif
