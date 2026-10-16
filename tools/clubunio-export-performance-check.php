<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\FamilyReportResource;
use App\ClubUnio\Resources\MemberReportResource;
use App\ClubUnio\Resources\OpenInvoiceResource;
use App\ClubUnio\Resources\PaymentReportResource;
use App\ClubUnio\Resources\PlayerReportResource;
use App\ClubUnio\Resources\StaffReportResource;
use App\ClubUnio\Services\FinanceExportService;
use App\ClubUnio\Services\MembershipReportService;
use App\ClubUnio\Services\ReportExportService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// CLI only. Export audit insertion is rolled back; never print CSV or bindings.
if (PHP_SAPI !== 'cli' || ($argv[2] ?? '') !== '--rollback-audit') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$kind = $argv[1] ?? '';
$resources = ['member_reports' => MemberReportResource::class, 'players' => PlayerReportResource::class,
    'staff' => StaffReportResource::class, 'families' => FamilyReportResource::class,
    'invoices' => OpenInvoiceResource::class, 'payments' => PaymentReportResource::class];
abort_unless(isset($resources[$kind]), 422);
$db = DB::connection('clubunio');
abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
$db->beginTransaction();
try {
    $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $actor = Account::where('active', true)->whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', $tenant->id)->where('tenant_accounts.role', 'admin'))->orderBy('id')->firstOrFail();
    Auth::guard('clubunio')->setUser($actor);
    Filament::setCurrentPanel(Filament::getPanel('clubunio'));
    Filament::setTenant($tenant);
    $resource = $resources[$kind];
    $query = $resource::getEloquentQuery()->orderBy($resource::getModel() === Member::class ? 'clubora.members.id' : 'id');
    if (in_array($kind, ['players', 'staff', 'families'], true)) {
        $query->where('active', true);
    }
    if ($kind === 'players') {
        $season = app(MembershipReportService::class)->currentSeason($tenant);
        abort_unless($season, 409);
        $query->whereHas('teamSeason', fn ($q) => $q->where('season_id', $season));
    }
    $count = 0;
    $queryMs = 0;
    $db->listen(function ($event) use (&$count, &$queryMs) {
        $count++;
        $queryMs += $event->time;
    });
    $start = microtime(true);
    $response = in_array($kind, ['invoices', 'payments'], true)
        ? app(FinanceExportService::class)->download($actor, $tenant, $kind, $query)
        : app(ReportExportService::class)->download($actor, $tenant, $kind, $query);
    $bytes = 0;
    ob_start(function ($chunk) use (&$bytes) {
        $bytes += strlen($chunk);

        return '';
    }, 8192);
    try {
        $response->sendContent();
    } finally {
        ob_end_flush();
    }
    echo json_encode(['kind' => $kind, 'status' => $response->getStatusCode(),
        'elapsed_ms' => round((microtime(true) - $start) * 1000), 'query_count' => $count,
        'query_ms' => round($queryMs), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'csv_bytes' => $bytes], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db->rollBack();
}
