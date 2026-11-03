<?php

use App\ClubUnio\Services\MobileModuleSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        // Match the tenant-first lock order used by profile/account changes.
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->statement('LOCK TABLE clubora.tenants IN SHARE ROW EXCLUSIVE MODE');
        $db->statement('ALTER TABLE clubora.access_profiles ADD COLUMN mobile_modules jsonb NULL CHECK (mobile_modules IS NULL OR jsonb_typeof(mobile_modules) = \'array\')');
        $db->statement('ALTER TABLE clubora.tenant_accounts ADD COLUMN mobile_profile_id bigint NULL, ADD CONSTRAINT tenant_accounts_mobile_profile_fk FOREIGN KEY (tenant_id,mobile_profile_id) REFERENCES clubora.access_profiles(tenant_id,id)');
        $db->statement('CREATE TABLE clubora.mobile_module_options (tenant_id bigint NOT NULL REFERENCES clubora.tenants(id), key varchar(80) NOT NULL, name varchar(160) NOT NULL, active boolean NOT NULL DEFAULT true, sort_order integer NOT NULL DEFAULT 0, PRIMARY KEY(tenant_id,key))');
        foreach ($db->table('clubora.tenants')->pluck('id') as $tenant) {
            $sort = 0;
            foreach (MobileModuleSettings::MODULES as $key=>$name) {
                $db->table('clubora.mobile_module_options')->insert(['tenant_id'=>$tenant,'key'=>$key,'name'=>$name,'sort_order'=>++$sort]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Profile module selections must be backed up before a reviewed rollback.');
    }
};
