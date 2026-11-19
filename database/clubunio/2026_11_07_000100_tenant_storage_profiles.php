<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        abort_unless(\App\ClubUnio\Services\StageMigrationTarget::allows($db), 409);
        $db->statement("CREATE TABLE clubunio.tenant_storage_profiles (
            id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
            provider varchar(12) NOT NULL CONSTRAINT tenant_storage_profiles_provider_check CHECK (provider IN ('azure', 'managed')),
            account varchar(24) NOT NULL DEFAULT '', container varchar(63) NOT NULL DEFAULT '',
            encrypted_key text, active boolean NOT NULL DEFAULT false,
            created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT tenant_storage_profiles_check CHECK ((provider = 'azure' AND account <> '' AND container <> '' AND encrypted_key IS NOT NULL)
                OR (provider = 'managed' AND account = '' AND container = '' AND encrypted_key IS NULL)),
            UNIQUE (tenant_id, provider, account, container))");
        $db->statement('CREATE UNIQUE INDEX tenant_storage_active ON clubunio.tenant_storage_profiles (tenant_id) WHERE active');
    }

    public function down(): void
    {
        throw new LogicException('Preserve storage profiles referenced by existing files.');
    }
};
