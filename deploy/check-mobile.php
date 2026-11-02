<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
abort_unless($db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'db_acdf8d_dclubora', 409, 'Unexpected deployment database');
foreach (['mobile_sessions', 'chat_conversations', 'chat_memberships', 'chat_messages', 'chat_polls', 'chat_votes',
    'mobile_inbox', 'mobile_invoice_events', 'mobile_ai_work', 'mobile_preferences', 'chat_changes', 'chat_blocks', 'chat_reports', 'chat_attachments'] as $table) {
    abort_unless($db->getSchemaBuilder()->hasTable('clubora.'.$table), 409, 'Missing mobile migration');
}
abort_unless(Route::has('clubunio.mobile.login') && Route::has('clubunio.mobile.context'), 409, 'Missing personal mobile routes');
abort_unless(config('clubunio-mobile.enabled'), 409, 'Mobile module is disabled');
abort_if(config('clubunio-mobile.push_enabled'), 409, 'Push is not implemented');
if (config('clubunio-mobile.uploads_enabled')) {
    $path = config('clubunio-mobile.attachments.clamd_socket');
    abort_unless(is_string($path) && preg_match('#^/[a-zA-Z0-9_./-]+$#D', $path), 409, 'Local clamd socket required');
    $socket = @stream_socket_client('unix://'.$path, $error, $description, 2);
    abort_unless($socket !== false, 409, 'Clamd connection unavailable');
    try {
        stream_set_timeout($socket, 3);
        abort_unless(fwrite($socket, "zPING\0") === 6 && stream_get_line($socket, 32, "\0") === 'PONG', 409, 'Clamd health check failed');
    } finally { fclose($socket); }
}
if (config('clubunio-mobile.ai.enabled')) {
    foreach (['translation_provider', 'writing_provider'] as $purpose) {
        $provider = config('clubunio-mobile.ai.'.$purpose);
        abort_unless(in_array($provider, ['openai', 'azure_openai', 'azure_translator'], true), 409, 'Invalid text provider');
        abort_if($purpose === 'writing_provider' && $provider === 'azure_translator', 409, 'Translator cannot rewrite drafts');
        $key = config('clubunio-mobile.ai.'.$provider.'.key');
        abort_unless(is_string($key) && strlen($key) >= 20, 409, 'Text provider credential missing');
        if ($provider === 'openai') {
            abort_unless(config('clubunio-mobile.ai.openai.model') === 'gpt-5.4-mini', 409, 'Unreviewed model pricing');
        } else {
            abort_unless((int) config('clubunio-mobile.ai.azure_reservation_microusd') > 0, 409, 'Reviewed Azure cost reservation missing');
        }
    }
}
echo "Mobile schema, routes, feature configuration and enabled scanner checks passed; no paid provider request made.\n";
