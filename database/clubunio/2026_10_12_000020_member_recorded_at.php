<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $db->statement('ALTER TABLE clubora.members ADD COLUMN recorded_at timestamp');
        // Import time is not a membership acquisition date. Preserve unknown legacy dates.
        $db->statement('UPDATE clubora.members m SET recorded_at = old."ErfasstAm"::timestamp FROM legacy_prod."Mitglieder" old, clubora.tenants t WHERE m.tenant_id=t.id AND t.slug=\'sv-sissach\' AND m.legacy_id=old."MitgliederID"');
        $db->statement('UPDATE clubora.members SET recorded_at=created_at WHERE legacy_id IS NULL');
        $db->statement("ALTER TABLE clubora.members ALTER COLUMN recorded_at SET DEFAULT timezone('Europe/Zurich', now())");
        $db->statement('CREATE INDEX members_recorded_idx ON clubora.members(tenant_id,recorded_at) WHERE archived_at IS NULL');
    }

    public function down(): void
    {
        throw new LogicException('Use reviewed restore');
    }
};
