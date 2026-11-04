<?php

// Standalone process: a separate PDO/backend PID, never a reused parent connection.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{PitchFriendlyRequestService, PitchPlanningService};
use Tests\Support\PitchConcurrencyDatabase;
use Illuminate\Support\Facades\{Http, Mail};

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = PitchConcurrencyDatabase::connect($input['schema']);
    // Pin a backend through transaction pooling before publishing its PID.
    $db->beginTransaction();
    $actor = Account::findOrFail($input['actor']);
    $tenant = Tenant::findOrFail($input['tenant']);
    echo json_encode(['ready' => true, 'pid' => (int) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'isolation' => $db->selectOne("SELECT current_setting('transaction_isolation') AS isolation")->isolation,
        'visible' => PitchConcurrencyDatabase::counts($db)], JSON_THROW_ON_ERROR)."\n";
    flush();
    $service = app(PitchPlanningService::class);
    try {
        $entry = match ($input['operation']) {
            'decide' => $service->decide($actor, $tenant, $input['id'], $input['data']),
            'move' => $service->move($actor, $tenant, $input['key'], $input['data']),
            'submit' => app(PitchFriendlyRequestService::class)->submit($actor, $tenant, $input['data'], $input['request_id']),
            default => throw new LogicException('Unknown race operation'),
        };
        $result = ['status' => 200, 'entry' => $entry];
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
        $result = ['status' => $error->getStatusCode()];
    }
    $db->commit();
    Mail::assertNothingSent();
    Http::assertNothingSent();
    echo json_encode($result + ['visible' => PitchConcurrencyDatabase::counts($db)], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    $failed = true;
} finally {
    if (isset($db)) {
        while ($db->transactionLevel()) $db->rollBack();
        $db->disconnect();
    }
}
exit(isset($failed) ? 1 : 0);
