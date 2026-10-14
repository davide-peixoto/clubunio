<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use App\ClubUnio\Services\InvoicePdfService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora' || ! in_array('--apply', $argv, true)) {
    throw new LogicException('Own database and explicit --apply required');
}
try {
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
    $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $result = app(BexioSandboxFixtureService::class)->seed($actor, $tenant);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    if (in_array('--verify-pdf', $argv, true)) {
        $response = app(InvoicePdfService::class)->open($actor, $tenant, (int) $result['invoice_id'], true);
        if ($response->getStatusCode() !== 200 || ! str_starts_with($response->getContent(), '%PDF-')) {
            throw new RuntimeException('Web PDF check failed', $response->getStatusCode());
        }
        echo 'Web PDF verified; bytes='.strlen($response->getContent()).PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture stopped: '.get_class($error).' code '.(int) $error->getCode().PHP_EOL);
    exit(1);
}
