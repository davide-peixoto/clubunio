\set ON_ERROR_STOP on
BEGIN ISOLATION LEVEL REPEATABLE READ;
SET LOCAL ROLE userunio;
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';
DO $$ BEGIN
    IF current_database() <> 'clubunio_stage' THEN RAISE EXCEPTION 'Stage only'; END IF;
END $$;
LOCK TABLE clubora.expenses IN SHARE ROW EXCLUSIVE MODE;
LOCK TABLE clubora.expense_lines, clubora.team_seasons IN SHARE MODE;

CREATE TEMP TABLE approved (receipt int PRIMARY KEY, season int, old_assignment int, team int, new_assignment int) ON COMMIT DROP;
INSERT INTO approved VALUES
 (102,31,1431,120,1471), (133,30,1478,140,1435), (224,33,1498,1012,1537),
 (294,34,1541,1009,1560), (319,33,1558,310,1539), (337,33,1556,140,1534),
 (369,35,1558,310,1586), (371,35,1562,160,1590), (374,35,1559,320,1587),
 (375,35,1559,320,1587), (376,35,1559,320,1587), (419,34,1584,140,1556),
 (420,34,1584,140,1556), (502,35,1617,140,1584);

CREATE TEMP TABLE repair ON COMMIT DROP AS
SELECT e.tenant_id,e.id,e.revision,e.updated_at,e.season_id, old.id AS old_id,new.id AS new_id,
       to_jsonb(e)-ARRAY['team_season_id','revision','updated_at'] AS unchanged
FROM approved p
JOIN legacy_prod."Spesenbelege" src ON src."SpesenID"=p.receipt AND src."SaisonID"=p.season AND src."TeamSaisonID"=p.old_assignment
JOIN legacy_prod."TeamsSaison" oldsrc ON oldsrc."TeamSaisonID"=p.old_assignment AND oldsrc."TeamID"=p.team AND oldsrc."SaisonID"<>p.season
JOIN legacy_prod."TeamsSaison" newsrc ON newsrc."TeamSaisonID"=p.new_assignment AND newsrc."TeamID"=p.team AND newsrc."SaisonID"=p.season AND newsrc."sdeletedAt" IS NULL
JOIN clubora.tenants c ON c.slug='sv-sissach'
JOIN clubora.expenses e ON e.tenant_id=c.id AND e.legacy_id=p.receipt AND e.active AND e.revision=1
JOIN clubora.seasons s ON s.tenant_id=c.id AND s.id=e.season_id AND s.legacy_id=p.season
JOIN clubora.team_seasons old ON old.tenant_id=c.id AND old.id=e.team_season_id AND old.legacy_id=p.old_assignment AND old.season_id<>s.id
JOIN clubora.teams t ON t.tenant_id=c.id AND t.id=old.team_id AND t.legacy_id=p.team
JOIN clubora.team_seasons new ON new.tenant_id=c.id AND new.team_id=t.id AND new.season_id=s.id AND new.legacy_id=p.new_assignment AND new.active
WHERE (SELECT count(*) FROM clubora.team_seasons x WHERE x.tenant_id=c.id AND x.team_id=t.id AND x.season_id=s.id AND x.active)=1;

CREATE TEMP TABLE repair_lines ON COMMIT DROP AS
SELECT l.* FROM clubora.expense_lines l JOIN repair r ON r.tenant_id=l.tenant_id AND r.id=l.expense_id;

DO $$ DECLARE changed integer; actor bigint; BEGIN
    IF (SELECT count(*) FROM repair) <> 14 OR (SELECT count(DISTINCT id) FROM repair) <> 14 THEN
        RAISE EXCEPTION 'Expected exactly 14 unchanged Legacy mappings';
    END IF;
    SELECT ta.account_id INTO STRICT actor FROM clubora.tenant_accounts ta
    JOIN clubora.accounts a ON a.id=ta.account_id JOIN clubora.tenants c ON c.id=ta.tenant_id
    WHERE c.slug='sv-sissach' AND a.username='davide.peixoto@svsissach.ch' AND ta.role IN ('admin','office');

    UPDATE clubora.expenses e SET team_season_id=r.new_id, revision=e.revision+1, updated_at=CURRENT_TIMESTAMP
    FROM repair r WHERE e.tenant_id=r.tenant_id AND e.id=r.id AND e.team_season_id=r.old_id
      AND e.season_id=r.season_id AND e.revision=r.revision;
    GET DIAGNOSTICS changed=ROW_COUNT;
    IF changed<>14 THEN RAISE EXCEPTION 'Unexpected update count'; END IF;

    INSERT INTO clubora.object_changes(tenant_id,actor_id,object_type,object_id,before_value,after_value)
    SELECT tenant_id,actor,'expenses',id,
           jsonb_build_object('team_season_id',old_id,'revision',revision,'updated_at',updated_at),
           jsonb_build_object('team_season_id',new_id,'revision',revision+1,'correction_key','expense_team_season_fix_20261103',
                              'reason','Approved Stage replay; matched legacy receipt, team, season and assignments')
    FROM repair;

    IF EXISTS (SELECT 1 FROM repair r JOIN clubora.expenses e USING (tenant_id,id)
               WHERE (to_jsonb(e)-ARRAY['team_season_id','revision','updated_at']) IS DISTINCT FROM r.unchanged)
       OR EXISTS (SELECT * FROM repair_lines EXCEPT SELECT l.* FROM clubora.expense_lines l JOIN repair r ON r.tenant_id=l.tenant_id AND r.id=l.expense_id)
       OR EXISTS (SELECT l.* FROM clubora.expense_lines l JOIN repair r ON r.tenant_id=l.tenant_id AND r.id=l.expense_id EXCEPT SELECT * FROM repair_lines)
       OR EXISTS (SELECT 1 FROM clubora.expenses e JOIN clubora.team_seasons ts ON ts.tenant_id=e.tenant_id AND ts.id=e.team_season_id
                  JOIN clubora.tenants c ON c.id=e.tenant_id WHERE c.slug='sv-sissach' AND e.season_id<>ts.season_id) THEN
        RAISE EXCEPTION 'Unexpected changes or remaining season conflicts';
    END IF;
END $$;
SELECT count(*) AS verified_corrections,(SELECT count(*) FROM repair_lines) AS unchanged_positions FROM repair;
\if :apply
COMMIT;
\else
ROLLBACK;
\endif
