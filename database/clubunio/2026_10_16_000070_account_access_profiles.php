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
INSERT INTO clubora.account_roles(code,name,description,sort_order,active)
VALUES ('profile','Berechtigungsprofil','Explizite Rechte je Modul und Funktion',25,false);
ALTER TABLE clubora.tenant_accounts DROP CONSTRAINT tenant_accounts_role_check;
ALTER TABLE clubora.tenant_accounts ADD CONSTRAINT tenant_accounts_role_check
 CHECK(role IN ('admin','office','member','trainer','technical_lead','profile'));
ALTER TABLE clubora.access_profiles ADD CONSTRAINT access_profiles_tenant_id_id_unique UNIQUE (tenant_id,id);
ALTER TABLE clubora.tenant_accounts ADD COLUMN access_profile_id bigint NULL;
ALTER TABLE clubora.tenant_accounts ADD CONSTRAINT tenant_accounts_access_profile_fk
 FOREIGN KEY (tenant_id,access_profile_id) REFERENCES clubora.access_profiles(tenant_id,id);
ALTER TABLE clubora.tenant_accounts ADD CONSTRAINT tenant_accounts_access_profile_check
 CHECK ((role = 'profile' AND access_profile_id IS NOT NULL AND portal_enabled = false)
 OR (role <> 'profile' AND access_profile_id IS NULL));
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Assigned profiles must never fall back to unrestricted office access. Reviewed rollback required.');
    }
};
