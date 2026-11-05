<?php
// Read-only capability verification. Never approves real requests.
try {
    $root = dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || $root !== '/var/www/svs-laravel/releases/20261105-clubunio-pitch-admin-status-override') throw new LogicException('Fixed release required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.native_pitch_planning_enabled')) throw new LogicException('Unexpected configuration');
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Unexpected database');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->sole();
        $service = app(App\ClubUnio\Services\PitchPlanningService::class);
        $admin = App\ClubUnio\Models\Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
        $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/api/mobile/v1/clubs/'.$tenant->id.'/pitch-planning/options');
        $request->attributes->set('mobile.actor', $admin);
        $request->attributes->set('mobile.tenant', $tenant);
        $app->instance('request', $request);
        app(App\ClubUnio\Mobile\MobileAccess::class)->enter($admin, $tenant);
        if ($service->options($admin, $tenant)['capabilities']['override_field_status'] !== true) throw new LogicException('Admin capability missing');
        $request->attributes->set('clubunio_impersonation', true);
        if ($service->options($admin, $tenant)['capabilities']['override_field_status'] !== false) throw new LogicException('Impersonation capability leak');
        echo json_encode(['read_only' => true, 'admin_override_capability' => true, 'impersonation_closed' => true, 'real_approval_tested' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Admin pitch override check stopped: '.($error instanceof LogicException ? $error->getMessage() : get_class($error)).PHP_EOL);
    exit(1);
}
