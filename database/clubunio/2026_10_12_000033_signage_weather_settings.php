<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
        if (! $tenant) {
            throw new LogicException('Missing tenant');
        }
        foreach ([
            'SIGNAGE_WEATHER_ENABLED' => ['Wetter anzeigen (0/1)', 'boolean', '1'],
            'SIGNAGE_WEATHER_LAT' => ['Wetter Breitengrad', 'string', '47.46408'],
            'SIGNAGE_WEATHER_LON' => ['Wetter Längengrad', 'string', '7.80888'],
            'SIGNAGE_WEATHER_LABEL' => ['Wetter Ortsname', 'string', 'Sissach'],
        ] as $key => [$label, $type, $value]) {
            $db->table('clubora.tenant_settings')->insertOrIgnore(['tenant_id' => $tenant, 'area' => 'integrations', 'key' => $key, 'label' => $label, 'value_type' => $type, 'encrypted_value' => Crypt::encryptString($value), 'is_secret' => false, 'enabled' => true]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Use reviewed restore');
    }
};
