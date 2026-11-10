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
        $db->statement('ALTER TABLE clubora.tenant_storage_profiles
            DROP CONSTRAINT tenant_storage_profiles_provider_check, DROP CONSTRAINT tenant_storage_profiles_check,
            ALTER COLUMN account TYPE varchar(64), ALTER COLUMN container TYPE varchar(255)');
        $db->statement("ALTER TABLE clubora.tenant_storage_profiles ADD CONSTRAINT tenant_storage_provider_target CHECK (
            (provider IN ('azure', 's3', 'graph') AND account <> '' AND container <> '' AND encrypted_key IS NOT NULL)
            OR (provider = 'google' AND account = '' AND container <> '' AND encrypted_key IS NOT NULL)
            OR (provider = 'managed' AND account = '' AND container = '' AND encrypted_key IS NULL))");
        $db->statement("CREATE UNIQUE INDEX tenant_storage_external_target ON clubora.tenant_storage_profiles (provider, account, container)
            WHERE provider IN ('azure', 's3', 'google', 'graph')");
        $db->statement("CREATE UNIQUE INDEX tenant_storage_remote_bucket ON clubora.tenant_storage_profiles (provider, container)
            WHERE provider IN ('s3', 'google', 'graph')");
        $db->statement('CREATE TABLE clubora.tenant_storage_objects (
            profile_id uuid NOT NULL REFERENCES clubora.tenant_storage_profiles(id),
            object_key char(64) NOT NULL, remote_id varchar(255) NOT NULL,
            PRIMARY KEY (profile_id, object_key), UNIQUE (profile_id, remote_id))');
    }

    public function down(): void
    {
        throw new LogicException('Preserve remote storage references for existing files.');
    }
};
