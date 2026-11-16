<?php

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        $dev = $db->getDriverName() === 'pgsql'
            && $db->getDatabaseName() === 'clubunio_dev'
            && $db->selectOne('SELECT current_database() AS name')->name === 'clubunio_dev';
        abort_unless($dev || StageMigrationTarget::allows($db), 409);
        $db->unprepared(<<<'SQL'
ALTER TABLE clubora.tenants
    ADD COLUMN accounting_provider varchar(10) NOT NULL DEFAULT 'none'
    CHECK (accounting_provider IN ('none', 'bexio', 'erpnext'));
UPDATE clubora.tenants AS tenant
    SET accounting_provider = 'bexio'
    WHERE EXISTS (SELECT 1 FROM clubora.bexio_sandbox_fixtures WHERE tenant_id = tenant.id)
       OR EXISTS (
           SELECT 1 FROM clubora.tenant_settings
           WHERE tenant_id = tenant.id AND area = 'bexio'
             AND key = 'BEXIO_OIDC_CONNECTION' AND enabled AND encrypted_value IS NOT NULL
       );
SQL);
        $legacyKeys = ['BEXIO_ACTIVE', 'BEXIO_AKTIV', 'BEXIO_ENABLED'];
        $legacy = Setting::query()->where('area', 'bexio')->whereIn('key', $legacyKeys)
            ->get(['tenant_id', 'key', 'encrypted_value'])->groupBy('tenant_id');
        foreach ($legacy as $tenantId => $settings) {
            $rows = $settings->keyBy('key');
            $flag = $rows->get($legacyKeys[0]) ?? $rows->get($legacyKeys[1]) ?? $rows->get($legacyKeys[2]);
            $active = $flag->encrypted_value ? mb_strtolower(trim(Crypt::decryptString($flag->encrypted_value))) : '';
            if (in_array($active, ['1', 'ja', 'yes', 'true', 'on', 'aktiv'], true)) {
                $db->table('clubora.tenants')->where('id', $tenantId)->update(['accounting_provider' => 'bexio']);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required to preserve tenant provider choices.');
    }
};
