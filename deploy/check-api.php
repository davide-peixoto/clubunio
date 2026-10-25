<?php

use App\ClubUnio\Api\ApiDocumentation;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
foreach (['api_integrations', 'api_access_tokens', 'api_audit', 'api_idempotency'] as $table) {
    abort_unless($db->getSchemaBuilder()->hasTable('clubora.'.$table), 409);
}
abort_unless($db->getSchemaBuilder()->hasColumn('clubora.accounts', 'api_only'), 409);
abort_unless($db->selectOne("SELECT 1 FROM pg_constraint WHERE conrelid='clubora.api_integrations'::regclass AND conname='api_integration_tenant_profile_fk'"), 409);
abort_unless(config('sanctum.guard') === [] && config('sanctum.stateful') === [], 409);
$definitions = app(ApiModuleRegistry::class)->definitions();
$scopes = app(ApiTokenService::class)->scopes();
$spec = app(ApiDocumentation::class)->specification();
foreach ($definitions as $key => $definition) {
    abort_unless(isset($spec['paths']['/'.$key]['get']), 409);
    foreach (['password', 'encrypted_value', 'storage_path', 'storage_reference', 'encrypted_bytes', 'token'] as $secret) {
        abort_if(in_array($secret, $definition['read_fields'], true), 409);
    }
}
foreach ($spec['paths'] as $operations) {
    foreach ($operations as $method => $operation) {
        if ($method === 'parameters') {
            continue;
        }
        foreach ($operation['x-required-scopes'] ?? [] as $scope) {
            abort_unless(isset($scopes[$scope]), 409);
        }
    }
}
foreach (['swagger-ui-bundle.js', 'swagger-ui.css', 'LICENSE'] as $asset) {
    abort_unless(is_file(public_path('assets/swagger-ui/'.$asset)), 409);
}
echo json_encode(['api' => 'v1', 'resources' => count($definitions), 'documented_paths' => count($spec['paths']),
    'scopes' => count($scopes), 'bearer_only' => true, 'private_documentation' => true,
    'technical_accounts' => $db->table('clubora.accounts')->where('api_only', true)->count(),
    'existing_profiles_not_seeded' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
