<?php

// Read-only service/module smoke check; no session creation or user-right changes.
try {
    $root = dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux'
        || !in_array($root, ['/var/www/svs-laravel/releases/20261105-clubunio-facilities-status',
            '/var/www/svs-laravel/releases/20261105-clubunio-pitch-request-inbox'], true)) {
        throw new LogicException('Fixed ClubUnio release required');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch'
        || !config('clubunio-mobile.native_facilities_enabled')
        || config('clubunio-mobile-push.enabled') || config('clubunio-mobile-push.delivery_enabled')) {
        throw new LogicException('Unexpected feature state');
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected database');
    }
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
        $ids = $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)
            ->where('role', 'trainer')->orderBy('account_id')->pluck('account_id');
        $checked = 0;
        foreach ($ids as $id) {
            $actor = App\ClubUnio\Models\Account::find($id);
            if (!$actor || !app(App\ClubUnio\Services\MemberAccessService::class)->canEnter($actor, $tenant)
                || app(App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'field_availability', 'update')
                || !in_array('Platzverfugbarkeit', app(App\ClubUnio\Services\MobileModuleSettings::class)->visibleKeys($actor, $tenant), true)) continue;
            $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/modules');
            $request->attributes->set('mobile.actor', $actor);
            $request->attributes->set('mobile.tenant', $tenant);
            $request->attributes->set('mobile.season_scope', true);
            $request->headers->set('X-ClubUnio-Mobile-Features', 'pitch-planning-v1,facilities-v1');
            $app->instance('request', $request);
            app(App\ClubUnio\Mobile\MobileAccess::class)->enter($actor, $tenant);
            $modules = app(App\ClubUnio\Mobile\MobileAccountController::class)->modules($request)->getData(true);
            $module = collect($modules['data']['modules'])->firstWhere('key', 'Platzverfugbarkeit');
            if (!($module['facilities'] ?? false) || !($module['pitch_planning'] ?? false)) throw new LogicException('Trainer module mismatch');
            $data = app(App\ClubUnio\Mobile\MobileFacilitiesData::class)->read($actor, $tenant);
            if (!$data['fields'] || collect($data['fields'])->contains(fn ($row) => $row['can_edit'])) throw new LogicException('Trainer status projection mismatch');
            $checked++;
            break;
        }
        if ($checked !== 1) throw new LogicException('No eligible trainer found for read-only check');
        echo json_encode(['read_only' => true, 'trainer_module_and_status' => true, 'can_edit' => false,
            'field_count' => count($data['fields']), 'push_disabled' => true, 'mobile_session_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $db->rollBack();
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Facilities check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
