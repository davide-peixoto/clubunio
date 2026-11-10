<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{MemberAccessService, SeasonReferenceAudit};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com'
        || $db->getDatabaseName() !== 'clubunio_stage') throw new LogicException('Stage only');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $identity = $db->selectOne("SELECT current_database() AS db, current_schema() AS schema, to_regnamespace('clubora') AS old");
        if ($identity->db !== 'clubunio_stage' || $identity->schema !== 'clubunio' || $identity->old !== null) throw new LogicException('Schema identity mismatch');
        $tenant = Tenant::where('slug', 'sv-sissach')->sole();
        $audit = app(SeasonReferenceAudit::class)->inspect($db, (int) $tenant->id);
        if (! $audit['configured_lookup'] || $audit['expense_team_season_disagreement'] !== 0
            || array_sum(array_column($audit['references'], 'invalid_or_cross_tenant')) !== 0
            || array_sum(array_column($audit['snapshot_mappings'], 'unexplained_reference_differences')) !== 0
            || ! app(MemberAccessService::class)->activeMembers($tenant)->exists()
            || $db->table('clubunio.migrations')->count() !== 117
            || $db->table('clubunio.accounts')->where('active', true)->exists()
            || $db->table('clubunio.tenant_settings')->where('is_secret', true)->where('enabled', true)->exists()) {
            throw new LogicException('Stage data acceptance failed');
        }
        echo "Stage application connection, tenant, migrations and season references verified.\n";
    } finally {
        $db->rollBack();
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Stage schema check failed: '.get_class($error).' ('.$error->getCode().')'.PHP_EOL);
    exit(1);
}
