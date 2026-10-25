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
            throw new LogicException('Reviewed ClubUnio target required');
        }
        $db->statement("SET LOCAL lock_timeout TO '5s'");
        $db->statement("SET LOCAL statement_timeout TO '60s'");
        // Validate existing data; never silently rewrite a foreign profile assignment.
        $db->statement('ALTER TABLE clubora.api_integrations ADD CONSTRAINT api_integration_tenant_profile_fk '
            .'FOREIGN KEY (tenant_id,access_profile_id) REFERENCES clubora.access_profiles(tenant_id,id)');
    }

    public function down(): void
    {
        throw new LogicException('Security constraint retained; rollback requires separate review.');
    }
};
