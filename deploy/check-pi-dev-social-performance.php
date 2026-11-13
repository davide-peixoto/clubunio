<?php

require __DIR__.'/verify-pi-dev-vps-database.php';

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Crypt, Http};
use Illuminate\Support\Str;

Http::preventStrayRequests();
config(['session.driver' => 'array', 'session.secure' => true, 'cache.default' => 'array',
    'cache.limiter' => 'array', 'logging.default' => 'stderr']);
$app->instance(Illuminate\Cache\RateLimiter::class, new Illuminate\Cache\RateLimiter(app('cache')->store('array')));
$app['session']->forgetDrivers();
$session = $app['session']->driver();
$session->start();
$kernel = $app->make(Kernel::class);
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
$actor = Account::findOrFail((int) ($argv[2] ?? 0));
$context = ['tenant' => (int) $tenant->id, 'revision' => (string) Str::uuid()];
$date = '2026-11-07';
$call = function (string $op, array $input = [], int $status = 200) use ($db, $actor, $context, $session, $kernel, $date) {
    Auth::forgetGuards();
    $session->put(Auth::guard('clubunio')->getName(), $actor->id);
    $session->put('clubunio_context.'.$actor->id, $context);
    $session->save();
    $cookie = Crypt::encryptString(CookieValuePrefix::create($session->getName(), app('encrypter')->getKey()).$session->getId());
    $request = Request::create('https://dev.clubunio.ch/social-data/'.$op, 'GET', $input + ['context' => $context, 'date' => $date],
        [$session->getName() => $cookie], [], ['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json']);
    $db->flushQueryLog();
    $start = hrtime(true);
    $response = $kernel->handle($request);
    $ms = (hrtime(true) - $start) / 1e6;
    if ($response->getStatusCode() !== $status || ($status === 200 && ! str_contains($response->headers->get('Cache-Control', ''), 'no-store'))) {
        throw new LogicException($op.' expected '.$status.' but got '.$response->getStatusCode());
    }
    $body = (string) $response->getContent();

    return [count($db->getQueryLog()), strlen($body), $ms, json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
};
$db->enableQueryLog();
$mode = $argv[1] ?? '';
$sections = ['today' => ['fixtures'], 'posts' => ['posts', 'test_channels'], 'templates' => ['templates'],
    'media' => ['media'], 'jobs' => ['job_data'], 'editor' => ['media', 'templates']];
if ($mode === 'baseline') {
    $scenarios = ['full' => [['state', []]], 'jobs (full + jobs)' => [['state', []], ['jobs', []]]];
} elseif ($mode === 'candidate') {
    $scenarios = [];
    $full = $call('state')[3];
    foreach ($sections as $name => $fields) {
        $payload = $call('state', ['section' => $name])[3];
        $unexpected = array_diff(array_keys($payload), ['date', 'club', 'more', 'permissions', ...$fields]);
        if ($unexpected || array_diff($fields, array_keys($payload)) || $payload['permissions'] !== $full['permissions']) {
            throw new LogicException('Unexpected scope for '.$name);
        }
        foreach ($fields as $field) {
            if ($payload[$field] !== ($field === 'job_data' ? $call('jobs')[3] : $full[$field])) {
                throw new LogicException('Projection differs for '.$field);
            }
        }
        $scenarios[$name] = [['state', ['section' => $name]]];
    }
    $call('state', ['section' => 'today', 'context' => [...$context, 'revision' => (string) Str::uuid()]], 419);
    $call('state', ['section' => 'unknown'], 422);
} else {
    throw new LogicException('Expected baseline or candidate.');
}
foreach ($scenarios as $name => $requests) {
    $times = [];
    for ($i = 0; $i < 8; $i++) {
        $queries = $bytes = $ms = 0;
        foreach ($requests as [$op, $params]) {
            [$q, $b, $t] = $call($op, $params);
            $queries += $q;
            $bytes += $b;
            $ms += $t;
        }
        if ($i > 0) {
            $times[] = $ms;
        }
    }
    sort($times);
    printf("%s: %d SQL, %d JSON bytes, %.1f ms median\n", $name, $queries, $bytes, $times[3]);
}
if (Http::recorded()->isNotEmpty()) {
    throw new LogicException('Unexpected outbound request');
}
echo "Social performance probe OK\n";
