<?php

// Read-only inventory for the explicitly requested SV Sissach training correction.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    if (array_slice($argv, 1) !== ['--read-only']) throw new LogicException('Read-only mode required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Exact database required');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->sole();
        $fields = $db->table('clubora.training_options')->where('tenant_id', $tenant->id)->where('category', 'field')
            ->orderBy('id')->get(['id', 'name', 'active']);
        $slots = $db->table('clubora.training_slots as slot')->join('clubora.team_seasons as ts', fn ($j) => $j
            ->on('ts.id', '=', 'slot.team_season_id')->on('ts.tenant_id', '=', 'slot.tenant_id'))
            ->where('slot.tenant_id', $tenant->id)->orderBy('slot.id')
            ->get(['slot.id', 'slot.field_id', 'slot.area_id', 'slot.period_id', 'slot.weekday_id',
                'slot.starts_at', 'slot.ends_at', 'slot.active', 'ts.season_id']);
        echo json_encode(['read_only' => true, 'tenant_id' => $tenant->id, 'fields' => $fields,
            'areas' => $db->table('clubora.pitch_areas')->where('tenant_id', $tenant->id)->orderBy('id')
                ->get(['id', 'field_id', 'name_de', 'name_en', 'x', 'y', 'width', 'height']),
            'slots_total' => $slots->count(), 'missing_field' => $slots->whereNull('field_id')->count(),
            'allocated' => $slots->whereNotNull('area_id')->count(),
            'with_field' => $slots->whereNotNull('field_id')->values(),
            'exceptions' => $db->table('clubora.pitch_training_exceptions')->where('tenant_id', $tenant->id)->count(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Training inventory failed: '.get_class($error).' (private details suppressed).'.PHP_EOL);
    exit(1);
}
