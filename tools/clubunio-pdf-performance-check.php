<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\InvoicePdfService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// CLI only: read the existing sandbox fixture, never print PDF/provider bodies.
if (PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '', ['bexio', 'local'], true)) {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)->where('tenant_accounts.role', 'admin'))->orderBy('id')->firstOrFail();
$id = $db->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->value('invoice_id');
abort_unless($id, 409);
$queries = 0;
$queryMs = 0;
$providers = [];
$db->listen(function ($query) use (&$queries, &$queryMs) {
    $queries++;
    $queryMs += $query->time;
});
Event::listen(RequestSending::class, function ($event) use (&$providers) {
    $path = parse_url($event->request->url(), PHP_URL_PATH);
    $kind = str_ends_with($path, '/pdf') ? 'pdf' : (str_contains($path, 'company_profile') ? 'profile' : (str_contains($path, 'token') ? 'oauth' : 'identity'));
    $providers[$kind] = ($providers[$kind] ?? 0) + 1;
});
// No outer rollback: a normal OIDC token rotation must remain persisted.
$start = microtime(true);
$response = app(InvoicePdfService::class)->open($actor, $tenant, (int) $id, $argv[1] === 'bexio');
echo json_encode(['source' => $argv[1], 'status' => $response->getStatusCode(),
    'elapsed_ms' => round((microtime(true) - $start) * 1000), 'queries' => $queries,
    'query_ms' => round($queryMs), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'pdf_bytes' => $response->getStatusCode() === 200 ? strlen($response->getContent()) : 0,
    'provider_requests' => $providers], JSON_THROW_ON_ERROR).PHP_EOL;
