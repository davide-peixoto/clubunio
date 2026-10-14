<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared(<<<'SQL'
CREATE VIEW clubora.spots_recipients AS
SELECT 'm-' || m.id AS id, m.tenant_id, 'member'::text AS kind, m.id AS member_id,
 NULL::bigint AS family_id, m.legacy_id, m.company,
 concat_ws(' ',nullif(m.first_name,''),nullif(m.last_name,'')) AS name,
 m.street, m.postal_code, m.city, c.name AS country,
 mt.name AS member_type, ms.name AS member_status
FROM clubora.members m
JOIN clubora.member_statuses ms ON ms.tenant_id=m.tenant_id AND ms.id=m.status_id
JOIN clubora.member_types mt ON mt.tenant_id=m.tenant_id AND mt.id=m.type_id
LEFT JOIN clubora.countries c ON c.tenant_id=m.tenant_id AND c.id=m.country_id
WHERE m.spots=true AND m.archived_at IS NULL AND NOT ms.ends_membership
 AND (m.exit_date IS NULL OR m.exit_date > (CURRENT_TIMESTAMP AT TIME ZONE 'Europe/Zurich')::date)
 AND NOT EXISTS (
 SELECT 1 FROM clubora.family_members fm JOIN clubora.families f ON f.tenant_id=fm.tenant_id AND f.id=fm.family_id
 WHERE fm.tenant_id=m.tenant_id AND fm.member_id=m.id AND fm.active AND f.active)
UNION ALL
SELECT 'f-' || f.id, f.tenant_id, 'family', NULL::bigint, f.id, f.legacy_id,
 NULL::text, f.name, f.street, f.postal_code, f.city, c.name, NULL::text, NULL::text
FROM clubora.families f
LEFT JOIN clubora.countries c ON c.tenant_id=f.tenant_id AND c.id=f.country_id
WHERE f.active;
SQL);
    }

    public function down(): void
    {
        DB::connection('clubunio')->statement('DROP VIEW clubora.spots_recipients');
    }
};
