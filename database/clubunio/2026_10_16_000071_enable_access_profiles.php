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
        // Versioned seed: future modules are not silently granted to existing profiles.
        $modules = ['members', 'registrations', 'member_changes', 'documents', 'families', 'seasons', 'teams',
            'planning', 'training', 'training_slots', 'competitions', 'invoices', 'expenses', 'contributions',
            'credits', 'bexio', 'bus', 'facilities', 'signage', 'announcements', 'events', 'reports', 'catalogs', 'templates'];
        foreach ($db->table('clubora.tenants')->pluck('id') as $tenantId) {
            if (! $db->table('clubora.access_profiles')->where('tenant_id', $tenantId)->where('name', 'Nur Lesen')->exists()) {
                $db->table('clubora.access_profiles')->insert([
                    'tenant_id' => $tenantId, 'name' => 'Nur Lesen', 'description' => 'Anzeige ohne Fachaktionen, Downloads oder Exporte.',
                    'permissions' => json_encode(array_map(fn ($module) => $module.'.view', $modules), JSON_THROW_ON_ERROR),
                    'active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $db->table('clubora.account_roles')->where('code', 'profile')->update(['active' => true]);
    }

    public function down(): void
    {
        throw new LogicException('Disable the profile role before rollback; never convert assignments to office.');
    }
};
