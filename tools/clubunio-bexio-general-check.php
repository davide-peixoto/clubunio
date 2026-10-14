<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioReconciliationService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
try {
    if (in_array('--prepare', $argv, true)) {
        echo json_encode(app(BexioGeneralSandboxFixtureService::class)->prepare($actor, $tenant), JSON_THROW_ON_ERROR).PHP_EOL;
    }
    foreach (['invoice' => Invoice::class, 'expense' => Expense::class] as $kind => $class) {
        $doc = $class::where('tenant_id', $tenant->id)->whereNull('legacy_id')->where('notes', 'SANDBOX allgemeiner Webversand v1 - keine echte Forderung oder Zahlung')->firstOrFail();
        if (in_array('--send', $argv, true)) {
            $existing = $db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where($kind.'_id', $doc->id)->first();
            $service = app(BexioSandboxDispatchService::class);
            if (! $existing) {
                $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, $kind, $doc->id);
                $id = $service->prepareTrial($actor, $tenant, $trial);
            } else {
                $id = $existing->id;
            }
            $service->send($actor, $tenant, $id);
        }
        if (in_array('--status', $argv, true)) {
            $service = app(BexioReconciliationService::class);
            $check = $service->preview($actor, $tenant, $kind, $doc->id);
            $row = $db->table('clubora.bexio_checks')->find($check);
            echo json_encode(['type' => $kind, 'check' => $check, 'state' => $row->state, 'remote_status' => $row->remote_status], JSON_THROW_ON_ERROR).PHP_EOL;
            if (in_array('--apply-status', $argv, true) && $row->state === 'ready') {
                $service->apply($actor, $tenant, $check);
                echo 'Status applied.'.PHP_EOL;
            }
        }
        echo json_encode($db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where($kind.'_id', $doc->id)->first(['id', 'source_trial_id', 'kind', 'invoice_id', 'expense_id', 'state', 'remote_id', 'expected_cents']), JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $error) {
    if ($error instanceof ValidationException || $error instanceof HttpExceptionInterface) {
        fwrite(STDERR, $error->getMessage().PHP_EOL);
    }
    fwrite(STDERR, get_class($error).' code '.(int) $error->getCode().PHP_EOL);
    exit(1);
}
