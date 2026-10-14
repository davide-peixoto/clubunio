<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use App\ClubUnio\Services\SettingsService;
use App\ClubUnio\Services\SignageFeedService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
abort_unless($actor->fresh()->canAccessTenant($tenant), 403);
$operation = $argv[1] ?? 'inspect';
if ($operation === 'inspect') {
    echo json_encode(Setting::where('tenant_id', $tenant->id)->where('area', 'integrations')->where(fn ($q) => $q->where('key', 'ilike', '%igloo%')->orWhere('key', 'ilike', '%igcode%')->orWhere('key', 'ilike', '%instagram%'))
        ->get()->map(fn ($s) => ['key' => $s->key, 'enabled' => $s->enabled, 'secret' => $s->is_secret, 'configured' => trim(Crypt::decryptString($s->encrypted_value)) !== '']), JSON_THROW_ON_ERROR).PHP_EOL;
    $shapes = [];
    foreach ($db->table('legacy_prod.TrainingPlannerAuditLog')->where('Status', 'success')->get() as $row) {
        $plan = json_decode($row->PlanJson, true, 64, JSON_THROW_ON_ERROR);
        $shape = json_encode(['phases' => array_values(array_unique(array_column($plan['exercises'], 'phase'))), 'diagramKeys' => array_keys($plan['exercises'][0]['diagram'] ?? []),
            'diagramDescription' => is_string($plan['exercises'][0]['diagram']['description'] ?? null), 'durationSum' => array_sum(array_column($plan['exercises'], 'durationMinutes')),
            'duration' => $row->DurationMinutes, 'ownerMatches' => $db->table('clubora.accounts')->whereRaw('lower(username)=lower(?)', [$row->Username])->count(),
            'hasSourceTeam' => $row->SharedTeamSaisonID !== null]);
        $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    }
    echo json_encode($shapes, JSON_THROW_ON_ERROR).PHP_EOL;
    $failures = [];
    foreach ($db->table('legacy_prod.TrainingPlannerAuditLog')->where('Status', 'success')->get() as $source) {
        $converted = app(LegacyTrainingPlanImporter::class)->convert($source);
        $key = $converted['error_code'] ?? 'valid';
        $failures[$key] = ($failures[$key] ?? 0) + 1;
    }
    echo json_encode($failures, JSON_THROW_ON_ERROR).PHP_EOL;
    $sample = json_decode($db->table('legacy_prod.TrainingPlannerAuditLog')->where('Status', 'success')->value('PlanJson'), true, 64, JSON_THROW_ON_ERROR)['exercises'][0];
    echo json_encode(['easier_type' => get_debug_type($sample['easier']), 'easier_keys' => is_array($sample['easier']) ? array_keys($sample['easier']) : [],
        'harder_type' => get_debug_type($sample['harder']), 'harder_keys' => is_array($sample['harder']) ? array_keys($sample['harder']) : []], JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode($db->table('clubora.buses')->where('tenant_id', $tenant->id)->get(['id', 'legacy_id', 'name', 'active']), JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'stage') {
    echo json_encode(app(LegacyTrainingPlanImporter::class)->stage($actor, $tenant), JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode($db->table('clubora.legacy_training_plans')->where('tenant_id', $tenant->id)->selectRaw('state,error_code,count(*) AS total')->groupBy('state', 'error_code')->get(), JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'import-unambiguous') {
    $service = app(LegacyTrainingPlanImporter::class);
    $rows = LegacyTrainingPlan::where('tenant_id', $tenant->id)->where('state', 'review')->whereNotNull('suggested_owner_id')->whereNotNull('suggested_team_season_id')->get();
    $ids = [];
    foreach ($rows as $row) {
        $teams = $service->teams($actor, $tenant, $row->suggested_owner_id);
        if (isset($teams[$row->suggested_team_season_id])) {
            $ids[] = $service->import($actor, $tenant, $row->id, ['revision' => $row->revision, 'owner_id' => $row->suggested_owner_id, 'team_season_id' => $row->suggested_team_season_id,
                'decision_note' => 'Eindeutige Originalzuordnung aus Username und SharedTeamSaisonID; private Archivübernahme.', 'schematic_confirmed' => true]);
        }
    }
    echo json_encode(['imported_plan_ids' => $ids], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif (in_array($operation, ['instagram', 'instagram-enable'], true)) {
    $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'integrations')->where('key', 'INSTAGRAM_ACCESS_TOKEN')->firstOrFail();
    $result = app(SignageFeedService::class)->verifyInstagram($actor, $tenant);
    if ($operation === 'instagram-enable' && $result['state'] === 'ready') {
        app(SettingsService::class)->enableInstagram($actor, $tenant, $setting->id, true, (bool) $setting->enabled);
        $result['feed_enabled'] = true;
    }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    throw new LogicException('Unsupported operation');
}
