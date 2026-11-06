<?php

use App\ClubUnio\Assistant\AssistantProvider;
use App\ClubUnio\Assistant\AssistantReadTools;
use App\ClubUnio\Assistant\BackofficeAssistantService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

try {
    if (PHP_SAPI !== 'cli') {
        exit(1);
    }
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    if (config('app.url') !== 'https://dev.clubunio.ch' || $db->getDatabaseName() !== 'db_acdf8d_dclubora'
        || config('clubunio-assistant.provider_enabled') || config('clubunio-assistant.privacy_reviewed')
        || app(AssistantProvider::class)->enabled()) {
        throw new LogicException('Expected ClubUnio only, provider disabled');
    }
    Http::preventStrayRequests();
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION READ ONLY');
        $db->statement("SET LOCAL statement_timeout='20s'");
        foreach (['conversations', 'runs', 'actions'] as $table) {
            if (! Schema::connection('clubunio')->hasTable('clubora.assistant_'.$table)) {
                throw new LogicException('Missing assistant table');
            }
        }
        $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
        $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->firstOrFail();
        $state = app(BackofficeAssistantService::class)->state($actor, $tenant);
        if ($state['provider_enabled'] || $state['conversation_id'] !== null) {
            throw new LogicException('Unexpected initial state');
        }
        $result = app(AssistantReadTools::class)->execute($actor, $tenant, 'read_daily_briefing', ['date' => null]);
        if (! isset($result['title'], $result['rows'])) {
            throw new LogicException('Invalid local briefing');
        }
        if (! collect(Route::getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'backoffice-assistant'))) {
            throw new LogicException('Missing assistant route');
        }
        echo "Assistant schema, local reads, state and route verified; no provider request or domain mutation.\n";
    } finally {
        $db->rollBack();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Assistant gate failed: '.($e instanceof LogicException ? $e->getMessage() : get_class($e)).PHP_EOL);
    exit(1);
}
