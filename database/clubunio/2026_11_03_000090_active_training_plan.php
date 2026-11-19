<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Crypt, DB};

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        foreach ($db->table('clubunio.tenants')->pluck('id') as $tenant) {
            $row = $db->table('clubunio.tenant_settings')->where('tenant_id', $tenant)
                ->where('key', 'SIGNAGE_PERIOD_ID')->first();
            if ($row) {
                // Keep the row ID, audit history and current effective value.
                if ($row->area === 'general') { continue; }
                $db->table('clubunio.tenant_settings')->where('id', $row->id)->update([
                    'area' => 'general', 'label' => 'Aktiver Trainingsplan',
                    'encrypted_value' => $row->enabled ? $row->encrypted_value : Crypt::encryptString('0'),
                    'enabled' => true, 'updated_at' => now(),
                ]);
            } else {
                $db->table('clubunio.tenant_settings')->insert([
                    'tenant_id' => $tenant, 'area' => 'general', 'key' => 'SIGNAGE_PERIOD_ID',
                    'label' => 'Aktiver Trainingsplan', 'value_type' => 'integer',
                    'encrypted_value' => Crypt::encryptString('0'), 'enabled' => true, 'is_secret' => false,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::connection('clubunio')->table('clubunio.tenant_settings')
            ->where('area', 'general')->where('key', 'SIGNAGE_PERIOD_ID')
            ->update(['area' => 'signage', 'label' => 'Signage-Trainingsperiode']);
    }
};
