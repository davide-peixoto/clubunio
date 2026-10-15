<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
        $db->statement('SET LOCAL search_path TO clubora');
        $db->unprepared(<<<'SQL'
CREATE TABLE public_registration_submissions (
 request_id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id),
 registration_id bigint NOT NULL, consent_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(tenant_id,registration_id) REFERENCES registrations(tenant_id,id),
 UNIQUE(tenant_id,registration_id)
);
SQL);
        foreach ($db->table('clubora.tenants')->get() as $tenant) {
            foreach (['SIGNAGE_PUBLIC_ENABLED' => 'Signage oeffentlich', 'REGISTRATION_PUBLIC_ENABLED' => 'Anmeldeformular oeffentlich'] as $key => $label) {
                $db->table('clubora.tenant_settings')->insertOrIgnore(['tenant_id' => $tenant->id, 'area' => 'public', 'key' => $key,
                    'label' => $label, 'value_type' => 'boolean', 'is_secret' => false, 'enabled' => true,
                    'encrypted_value' => Crypt::encryptString($tenant->slug === 'sv-sissach' ? '1' : '0')]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
