<?php

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 2 || ! in_array($argv[1], ['--closed', '--enabled'], true)) throw new LogicException('Explicit provenance stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $enabled = $argv[1] === '--enabled';
    if ((bool) config('clubunio-expense-provenance.enabled', false) !== $enabled) throw new LogicException('Provenance stage mismatch');
    if ($enabled) {
        $db = Illuminate\Support\Facades\DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
            || ! $db->table('clubora.migrations')->where('migration', '2026_11_04_000900_mobile_expense_ocr_provenance')->exists()) throw new LogicException('Reviewed provenance900 required');
        foreach (['line_revision', 'work_id', 'source_sha256', 'suggested_amount', 'saved_amount', 'source_replaced', 'accepted'] as $column) {
            if (! $db->getSchemaBuilder()->hasColumn('clubora.mobile_expense_ocr_provenance', $column)) throw new LogicException('Provenance schema incomplete');
        }
    } else {
        $fields = app(App\ClubUnio\Mobile\MobileExpenseOcrProvenance::class)->fields(new App\ClubUnio\Models\Tenant, new App\ClubUnio\Models\ExpenseLine);
        if ($fields !== ['AiBetragUebernommen' => null]) throw new LogicException('Closed provenance must not read missing schema');
    }
    echo 'Personal amount provenance '.($enabled ? 'enabled schema' : 'closed foundation')." verified; no receipt, provider, payment or member data changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Provenance gate failed: '.get_class($error)."\n"); exit(1);
}
