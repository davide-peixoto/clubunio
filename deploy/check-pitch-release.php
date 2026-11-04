<?php

// Read-only closed-provider/schema/route gate. Not a substitute for synthetic tests.
try {
    $root = '/var/www/svs-laravel/releases/20261104-clubunio-pitch-v1';
    $mode = array_slice($argv, 1);
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__FILE__) !== $root.'/deploy/check-pitch-release.php'
        || !in_array($mode, [['--staged'], ['--closed'], ['--native-enabled']], true)) throw new LogicException('Fixed release/mode required');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (['clubunio-integrations.pitch_planning_test_mail_enabled', 'clubunio-mobile-push.enabled',
        'clubunio-mobile-push.delivery_enabled', 'clubunio-mobile.public_identity.mail_enabled',
        'clubunio-mobile.ai.enabled','clubunio-mobile.training_ai.enabled',
        'clubunio-mobile.keybox.issuance_enabled','clubunio-mobile.keybox.provider_enabled'] as $flag) {
        if (config($flag, false)) throw new LogicException('Provider operation open');
    }
    if ((bool) config('clubunio-mobile.native_pitch_planning_enabled', false) !== ($mode === ['--native-enabled'])) throw new LogicException('Unexpected native pitch flag');
    if ($mode === ['--staged']) {
        echo "Staged runtime: native pitch, pitch mail and provider operation flags closed.\n";
        exit(0);
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
        || config('app.url') !== 'https://dev.clubunio.ch') throw new LogicException('Fixed DEV required');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        if (!app(App\ClubUnio\Services\PitchPlanningProjection::class)->ready()) throw new LogicException('Pitch projection not ready');
        foreach (['2026_11_04_001500_pitch_planning','2026_11_04_001600_pitch_planning_notifications',
            '2026_11_04_001700_pitch_weekly_areas','2026_11_04_001800_pitch_request_idempotency'] as $name) {
            if ($db->table('clubora.migrations')->where('migration', $name)->count() !== 1) throw new LogicException('Exact pitch ledger missing');
        }
        if (!$db->selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid='clubora.training_slots'::regclass AND attname='area_id' AND NOT attisdropped) AS present")->present
            || !$db->selectOne("SELECT to_regclass('clubora.pitch_request_idempotency') IS NOT NULL AS present")->present) throw new LogicException('Weekly/idempotency schema missing');
        $routes = array_filter(iterator_to_array(app('router')->getRoutes()), fn ($r) => str_starts_with($r->getName() ?? '', 'clubunio.mobile.pitch.'));
        if (count($routes) < 7) throw new LogicException('Pitch routes missing');
        echo json_encode(['read_only' => true, 'schema_ready' => true, 'pitch_routes' => count($routes),
            'native_pitch_enabled' => $mode === ['--native-enabled'], 'push_enabled' => false,
            'push_delivery_enabled' => false, 'configured_provider_present' => count(config('clubunio-mobile-push.apps', [])) > 0,
            'pitch_test_mail_enabled' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $e) {
    fwrite(STDERR, 'Pitch release gate stopped: '.($e instanceof LogicException ? $e->getMessage() : get_class($e)).PHP_EOL);
    exit(1);
}
