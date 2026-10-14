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
        // Only untouched imported events; never reimport locally edited scheduling data.
        $db->unprepared('UPDATE clubora.team_events e SET
            starts_at=l."DatumVon"::timestamp AT TIME ZONE \'Europe/Zurich\',
            ends_at=l."DatumBis"::timestamp AT TIME ZONE \'Europe/Zurich\',
            deadline_at=l."AnmeldeFrist"::timestamp AT TIME ZONE \'Europe/Zurich\'
            FROM legacy_prod."TeamTermine" l, clubora.tenants t
            WHERE e.legacy_id=l."TeamTerminID" AND e.revision=1 AND e.tenant_id=t.id AND t.slug=\'sv-sissach\'');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
