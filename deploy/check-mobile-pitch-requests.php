<?php

// Compares real projections to read-only queries, without creating a mobile login.
try {
    $root = dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux'
        || $root !== '/var/www/svs-laravel/releases/20261105-clubunio-pitch-request-inbox') throw new LogicException('Fixed release required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.native_pitch_planning_enabled')
        || config('clubunio-mobile-push.enabled') || config('clubunio-mobile-push.delivery_enabled')) throw new LogicException('Unexpected configuration');
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
        $service = app(App\ClubUnio\Services\PitchPlanningService::class);
        $actor = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
        $bind = function ($actor) use ($app, $tenant) {
            $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/pitch-planning/requests');
            $request->attributes->set('mobile.actor', $actor);
            $request->attributes->set('mobile.tenant', $tenant);
            $request->attributes->set('mobile.season_scope', true);
            $app->instance('request', $request);
            app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor, $tenant);
        };
        $bind($actor);
        $base = $db->table('clubora.pitch_requests')->where('tenant_id', $tenant->id);
        foreach ([null, 'pending', 'approved', 'rejected', 'cancelled'] as $status) {
            $query = clone $base;
            if ($status) $query->where('status', $status);
            $expected = $query->orderByDesc('id')->limit(30)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $data = $service->requests($actor, $tenant, $status ? ['status' => $status] : []);
            if (!$data['can_filter_status'] || array_column($data['entries'], 'source_id') !== $expected) throw new LogicException('Admin inbox mismatch');
        }
        $checked = 0;
        $ids = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('role', 'trainer')->orderBy('account_id')->pluck('account_id');
        foreach ($ids as $id) {
            $trainer = App\ClubUnio\Models\Account::find($id);
            if (!$trainer || !app(App\ClubUnio\Services\MemberAccessService::class)->canEnter($trainer, $tenant)
                || !in_array('Platzverfugbarkeit', app(App\ClubUnio\Services\MobileModuleSettings::class)->visibleKeys($trainer, $tenant), true)) continue;
            $bind($trainer);
            $data = $service->requests($trainer, $tenant);
            $expected = (clone $base)->where('requester_account_id', $id)->where('date', '>=', now('Europe/Zurich')->toDateString())
                ->orderByDesc('id')->limit(30)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($data['can_filter_status'] || array_column($data['entries'], 'source_id') !== $expected) throw new LogicException('Personal inbox mismatch');
            $checked++;
            break;
        }
        if ($checked !== 1) throw new LogicException('No eligible trainer checked');
        echo json_encode(['read_only' => true, 'admin_filters_checked' => 5, 'trainer_own_current_checked' => true,
            'trainer_entries' => count($data['entries']), 'mobile_login_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Request inbox check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
