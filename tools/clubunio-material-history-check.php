<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyMaterialReceipt;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\LegacyMaterialReceiptService;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
abort_unless($actor->fresh()->canAccessTenant($tenant), 403);
$operation = $argv[1] ?? 'status';
if ($operation === 'status') {
    echo json_encode(['legacy_receipts' => $db->table('clubora.legacy_material_receipts')->where('tenant_id', $tenant->id)->count(),
        'new_receipts' => $db->table('clubora.material_receipts')->where('tenant_id', $tenant->id)->count(),
        'training' => $db->table('clubora.legacy_training_plans')->where('tenant_id', $tenant->id)->selectRaw('state,count(*) AS total')->groupBy('state')->get(),
        'quality_pending' => $db->table('clubora.legacy_training_plans')->where('tenant_id', $tenant->id)->where('state', '<>', 'excluded')->where('requires_quality_review', true)->count(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'inspect') {
    echo json_encode(['legacy_receipts_count' => $db->table('legacy_prod.MitgliederQuittungen')->count(),
        'receipt_states' => $db->table('legacy_prod.MitgliederQuittungen')->selectRaw('"Status",count(*) AS total')->groupBy('Status')->get(),
        'legacy_receipts' => $db->table('legacy_prod.MitgliederQuittungen')->get(['QuittungID', 'MitgliederID', 'OriginalAnhangID', 'SigniertAnhangID', 'Status', 'UnterschriftNotwendig']),
        'training_requests' => $db->table('legacy_prod.TrainingPlannerAuditLog')->where('Status', 'success')->get()->map(fn ($r) => ['id' => $r->TrainingPlannerAuditLogID, 'at' => $r->CreatedAt, 'keys' => array_keys(json_decode($r->RequestJson, true)), 'team_keys' => array_filter(json_decode($r->RequestJson, true), fn ($k) => preg_match('/team|saison/i', $k), ARRAY_FILTER_USE_KEY)]),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    echo json_encode(['receipt_source_columns' => $db->table('information_schema.columns')->where('table_schema', 'legacy_prod')->where('table_name', 'MitgliederQuittungen')->pluck('column_name'),
        'training_queue' => $db->table('clubora.legacy_training_plans')->where('tenant_id', $tenant->id)->where('state', 'review')->get(['id', 'source_id', 'source_team_name', 'suggested_team_season_id', 'suggested_owner_id', 'requires_quality_review']),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'credit-status') {
    echo json_encode(app(CreditNoteService::class)->remote($actor, $tenant, 8440), JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'import-receipts') {
    echo json_encode(app(LegacyMaterialReceiptService::class)->import($actor, $tenant), JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($operation === 'verify-receipts') {
    foreach (LegacyMaterialReceipt::where('tenant_id', $tenant->id)->get() as $receipt) {
        foreach ([false, true] as $original) {
            $response = app(LegacyMaterialReceiptService::class)->pdf($actor, $tenant, $receipt->id, true, $original);
            echo json_encode(['id' => $receipt->id, 'original' => $original, 'status' => $response->getStatusCode(), 'sha256' => hash('sha256', $response->getContent())], JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }
} elseif (in_array($operation, ['training-preview', 'training-import'], true)) {
    echo json_encode(app(LegacyTrainingPlanImporter::class)->importHistorical($actor, $tenant, $operation === 'training-import'), JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    throw new LogicException('Unsupported operation');
}
