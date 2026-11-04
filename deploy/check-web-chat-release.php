<?php

// Read-only candidate readiness, before synthetic config overrides or activation.
try {
    if (PHP_SAPI !== 'cli' || array_slice($argv, 1) !== ['--closed']) throw new LogicException('Closed stage required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new LogicException('ClubUnio staging required');
    foreach (['clubunio-integrations.pitch_planning_test_mail_enabled', 'clubunio-mobile-push.enabled',
        'clubunio-mobile-push.delivery_enabled', 'clubunio-mobile.public_identity.mail_enabled',
        'clubunio-mobile.ai.enabled', 'clubunio-mobile.training_ai.enabled',
        'clubunio-mobile.keybox.issuance_enabled', 'clubunio-mobile.keybox.provider_enabled',
        'clubunio-mobile.native_pitch_planning_enabled'] as $flag) {
        if (config($flag, false)) throw new LogicException('Review external capability before proceeding');
    }
    $db = Illuminate\Support\Facades\DB::connection('clubunio');
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Exact database required');
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout TO '15s'");
        foreach (['chat_conversations', 'chat_memberships', 'chat_messages', 'chat_polls', 'chat_votes',
            'chat_reactions', 'chat_changes', 'chat_reports', 'chat_blocks', 'chat_attachments',
            'chat_preferences', 'mobile_inbox', 'mobile_push_outbox', 'access_profiles'] as $table) {
            if (!$db->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ['clubora.'.$table])->present) throw new LogicException('Existing schema missing');
        }
        if (!app(App\ClubUnio\Services\PitchPlanningProjection::class)->ready()) throw new LogicException('Existing pitch projection unavailable');
        $routes = app('router')->getRoutes();
        $route = $routes->getByName('clubunio.chat.data');
        if (!$route || $route->uri() !== 'chat-data/{operation}'
            || !in_array(App\ClubUnio\Http\UseActiveTenant::class, $route->gatherMiddleware(), true)
            || !in_array('auth:clubunio', $route->gatherMiddleware(), true)) throw new LogicException('Web boundary missing');
        if (route('filament.clubunio.pages.chats', [], false) !== '/chats') throw new LogicException('Shell route missing');
        echo json_encode(['read_only' => true, 'chat_schema_ready' => true, 'shell_path' => '/chats',
            'uploads_enabled' => (bool)config('clubunio-mobile.uploads_enabled'), 'ai_enabled' => false,
            'push_enabled' => false, 'push_delivery_enabled' => false, 'native_pitch_enabled' => false], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally { $db->rollBack(); }
} catch (Throwable $error) {
    fwrite(STDERR, 'Webchat readiness stopped: '.get_class($error).' (private details suppressed).'.PHP_EOL);
    exit(1);
}
