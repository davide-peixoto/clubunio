<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxClient;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use App\ClubUnio\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected database');
}
try {
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
    $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $service = app(BexioSandboxDispatchService::class);
    if (in_array('--bill-access', $argv, true)) {
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $result = app(BexioSandboxClient::class)->get($token, '/4.0/purchase/bills', ['limit' => 1]);
        echo 'Bill read access verified; sample count='.count($result['data'] ?? []).PHP_EOL;
    }
    if (in_array('--enable-file-scope', $argv, true)) {
        $service->authorize($actor, $tenant);
        $configuration = app(BexioConnectionService::class)->configuration($tenant);
        if (! in_array('file', $configuration['scopes'], true)) {
            $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', 'BEXIO_AUTHORIZE_SCOPES')->firstOrFail();
            app(SettingsService::class)->update($actor, $tenant, $setting->id, implode(' ', [...$configuration['scopes'], 'file']));
            echo 'File scope configured. New user authorization required.'.PHP_EOL;
        }
    }
    if (in_array('--verify-connection', $argv, true)) {
        $service->verifyReconnection($actor, $tenant);
        echo 'Reconnection verified.'.PHP_EOL;
    }
    if (in_array('--scopes', $argv, true)) {
        $connection = app(BexioConnectionService::class);
        echo 'Configured scopes: '.implode(' ', $connection->configuration($tenant)['scopes']).PHP_EOL;
        $parts = explode('.', $connection->accessToken($tenant));
        $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : [];
        // Diagnostic hint only, not authorization based on an unverified JWT claim.
        echo 'Token scope hint: '.(is_string($claims['scope'] ?? null) ? $claims['scope'] : 'unavailable').PHP_EOL;
    }
    foreach (['invoice', 'expense'] as $kind) {
        if (in_array('--prepare', $argv, true)) {
            $service->prepare($actor, $tenant, $kind);
        }
        $row = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('kind', $kind)->first();
        if ($row && $kind === 'expense' && in_array('--resume-upload', $argv, true)) {
            $service->resumeRejectedUpload($actor, $tenant, $row->id);
            $row = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->find($row->id);
        }
        if ($row && in_array('--send', $argv, true) && $row->state === 'ready') {
            $service->send($actor, $tenant, $row->id);
        }
        if ($row && in_array('--reconcile', $argv, true) && in_array($row->state, ['uncertain', 'running'], true)) {
            $service->reconcile($actor, $tenant, $row->id);
        }
        if ($row && $kind === 'expense' && $row->remote_id && in_array('--inspect-bill', $argv, true)) {
            $token = app(BexioConnectionService::class)->accessToken($tenant);
            app(BexioSandboxClient::class)->verify($token);
            $bill = app(BexioSandboxClient::class)->get($token, '/4.0/purchase/bills/'.$row->remote_id);
            echo json_encode(['fields' => array_keys($bill), 'amount_fields' => array_filter($bill, fn ($key) => str_contains($key, 'amount') || str_contains($key, 'total'), ARRAY_FILTER_USE_KEY)], JSON_THROW_ON_ERROR).PHP_EOL;
            echo json_encode(array_intersect_key($bill, array_flip(['id', 'vendor_ref', 'supplier_id', 'gross', 'status', 'attachment_ids', 'amount', 'total'])), JSON_THROW_ON_ERROR).PHP_EOL;
        }
        echo json_encode(DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('kind', $kind)
            ->first(['id', 'kind', 'invoice_id', 'expense_id', 'state', 'remote_id', 'attachment_id', 'http_status']), JSON_THROW_ON_ERROR).PHP_EOL;
        if ($row) {
            echo json_encode(DB::connection('clubunio')->table('clubora.bexio_sandbox_steps')->where('dispatch_id', $row->id)
                ->get(['operation', 'state', 'http_status']), JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }
} catch (Throwable $error) {
    if ($error instanceof HttpExceptionInterface) {
        fwrite(STDERR, 'Local validation: '.$error->getStatusCode().' '.$error->getMessage().PHP_EOL);
    }
    fwrite(STDERR, 'Sandbox check stopped: '.get_class($error).' code '.(int) $error->getCode().PHP_EOL);
    exit(1);
}
