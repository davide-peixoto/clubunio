<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\SignageBrandService;
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
        foreach (Tenant::all() as $tenant) {
            $data = app(SignageBrandService::class)->read($tenant);
            $data['title'] = $tenant->slug === 'sv-sissach' ? 'Sportverein Sissach' : $tenant->name;
            $data['period_id'] = $db->table('clubora.training_options')->where('tenant_id', $tenant->id)->where('category', 'period')->where('active', true)->orderBy('sort_order')->orderBy('id')->value('id') ?? 0;
            foreach (SignageBrandService::KEYS as $field => $key) {
                $db->table('clubora.tenant_settings')->insertOrIgnore(['tenant_id' => $tenant->id, 'area' => 'signage', 'key' => $key,
                    'label' => SignageBrandService::LABELS[$field],
                    'enabled' => true, 'is_secret' => false, 'value_type' => $field === 'period_id' ? 'integer' : 'string', 'encrypted_value' => Crypt::encryptString((string) $data[$field])]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
