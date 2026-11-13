<?php

require __DIR__.'/verify-pi-dev-vps-database.php';

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\{Request, UploadedFile};
use Illuminate\Support\Facades\{Auth, Crypt, Http, Storage};
use Illuminate\Support\Str;

if (config('clubunio-social.test_enabled')) {
    throw new LogicException('DEV test publishing must remain disabled during the release probe.');
}
Http::preventStrayRequests();
config(['session.driver' => 'array', 'session.secure' => true, 'cache.default' => 'array',
    'cache.limiter' => 'array', 'logging.default' => 'stderr']);
$app->instance(\Illuminate\Cache\RateLimiter::class, new \Illuminate\Cache\RateLimiter(app('cache')->store('array')));
$app['session']->forgetDrivers();
$session = $app['session']->driver();
$session->start();
$kernel = $app->make(Kernel::class);
$scratch = tempnam(sys_get_temp_dir(), 'social-probe-');
$paths = [];
$db->beginTransaction();
try {
    $tenant = Tenant::create(['slug' => 'synthetic-social-'.Str::lower(Str::random(12)), 'name' => 'Synthetic Social Club']);
    $actor = new Account;
    $actor->forceFill(['username' => 'social-probe-'.Str::random(16).'@example.invalid', 'display_name' => 'Synthetic editor',
        'password' => 'probe-not-a-password', 'active' => true, 'api_only' => false])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $competition = $db->table('clubunio.competitions')->insertGetId(['tenant_id' => $tenant->id,
        'plays_on' => '2026-09-12', 'starts_at' => '15:00', 'host_name' => 'Synthetic United', 'guest_name' => 'Sample FC', 'remarks' => 'NEVER_EXPORT']);
    $context = ['tenant' => (int) $tenant->id, 'revision' => (string) Str::uuid()];
    $call = function (string $verb, string $op, array $payload = [], int $status = 200, array $files = [], bool $stale = false, bool $csrf = true) use ($actor, $context, $session, $kernel) {
        Auth::forgetGuards();
        $session->put(Auth::guard('clubunio')->getName(), $actor->id);
        $session->put('clubunio_context.'.$actor->id, $context);
        $session->save();
        $cookie = Crypt::encryptString(CookieValuePrefix::create($session->getName(), app('encrypter')->getKey()).$session->getId());
        $payload['context'] = $stale ? [...$context, 'revision' => (string) Str::uuid()] : $context;
        $path = $op === 'page' ? '/social-media' : '/social-data/'.$op;
        $request = Request::create('https://dev.clubunio.ch'.$path, $verb, $payload,
            [$session->getName() => $cookie], $files, ['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_ACCEPT' => $op === 'page' ? 'text/html' : 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf ? $session->token() : '']);
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== $status) {
            throw new LogicException($op.' expected '.$status.' but got '.$response->getStatusCode());
        }

        return $op === 'page' ? $response->getContent() : json_decode((string) $response->getContent(), true);
    };
    $html = $call('GET', 'page');
    if (! str_contains($html, 'data-social-studio') || ! str_contains($html, '/build/assets/social-studio-')) {
        throw new LogicException('Editor page or build missing');
    }
    $call('GET', 'state', [], 419, stale: true);
    $call('POST', 'template-save', [], 419, csrf: false);
    $date = '2026-09-12';
    $state = $call('GET', 'state', ['date' => $date]);
    if (count($state['fixtures']) !== 1 || $state['test_channels'] !== [] || str_contains(json_encode($state), 'NEVER_EXPORT')) {
        throw new LogicException('Fixture projection, privacy or test-mode gate failed');
    }
    $fixture = $state['fixtures'][0];
    $call('POST', 'confirm-result', ['date' => $date, 'fixture' => $competition, 'host_score' => 3, 'guest_score' => 1, 'seen' => $fixture]);
    $fixture = $call('GET', 'state', ['date' => $date])['fixtures'][0];
    if ($fixture['score'] !== [3, 1]) {
        throw new LogicException('Confirmed result not returned');
    }
    $image = imagecreatetruecolor(1080, 1350);
    imagefilledrectangle($image, 0, 0, 1080, 1350, imagecolorallocate($image, 10, 65, 145));
    imagejpeg($image, $scratch);
    $upload = fn () => new UploadedFile($scratch, 'test.jpg', 'image/jpeg', null, true);
    $media = $call('POST', 'upload', ['name' => 'Synthetic background'], files: ['file' => $upload()]);
    $paths[] = $db->table('clubunio.social_media')->where('id', $media['id'])->value('path');
    $design = ['format' => 'portrait', 'background' => '#ffffff', 'layers' => [
        ['type' => 'image', 'x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350, 'angle' => 0, 'media' => $media['id']],
    ]];
    $template = $call('POST', 'template-save', ['name' => 'Synthetic template', 'design' => $design]);
    $call('POST', 'template-save', ['id' => $template['id'], 'revision' => 1, 'name' => 'Second version', 'design' => $design]);
    $call('POST', 'template-save', ['id' => $template['id'], 'revision' => 1, 'name' => 'Stale version', 'design' => $design], 409);
    foreach (['day', 'result'] as $kind) {
        $seen = $fixture;
        if ($kind === 'day') {
            unset($seen['score']);
        }
        $post = $call('POST', 'post-save', ['metadata' => json_encode([
            'title' => 'Synthetic '.$kind, 'kind' => $kind, 'targets' => ['instagram', 'facebook'], 'design' => $design,
            'date' => $date, 'fixtures' => [$competition], 'seen' => ['date' => $date, 'fixtures' => [$seen]],
        ], JSON_THROW_ON_ERROR)], files: ['image' => $upload()]);
        $paths[] = $db->table('clubunio.social_posts')->where('id', $post['id'])->value('image_path');
        $call('POST', 'post-approve', ['id' => $post['id'], 'revision' => 1]);
        $call('POST', 'publish', ['id' => $post['id'], 'revision' => 2, 'channel' => 'instagram'], 403);
    }
    $db->table('clubunio.competitions')->where('id', $competition)->update(['starts_at' => '16:00']);
    if ($call('GET', 'state', ['date' => $date])['fixtures'][0]['score'] !== null) {
        throw new LogicException('Stale result still visible');
    }
    if (Http::recorded()->isNotEmpty()) {
        throw new LogicException('Unexpected outbound request');
    }
} finally {
    $db->rollBack();
    Storage::disk('local')->delete(array_filter($paths));
    unlink($scratch);
}
if (isset($tenant) && $db->table('clubunio.tenants')->where('id', $tenant->id)->exists()) {
    throw new LogicException('Synthetic club did not roll back.');
}
echo "Social Studio authenticated page, CSRF, stale context, private fixture projection, crop-capable template versions, result and matchday approval, DEV Meta lock and rollback verified.\n";
