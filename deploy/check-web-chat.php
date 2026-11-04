<?php

namespace ClubUnioRelease;

use App\ClubUnio\Mobile\{ChatAccess, MalwareScanner, MobileAccess};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\{Auth, Crypt, DB, Http, Mail, Queue};
use Illuminate\Support\Str;

/** Bounded synthetic session/kernel proof; no migration, worker, or real provider. */
final class WebChatProbe
{
    public string $stage = 'bootstrap';
    public int $idleQueries = 0;
    private int $checks = 0;

    private function expect(bool $condition, string $stage): void
    {
        $this->stage = $stage;
        if (!$condition) throw new \LogicException('Synthetic web chat contract failed');
        $this->checks++;
    }

    public function run($app): int
    {
        $db = DB::connection('clubunio');
        $this->expect($db->getDriverName() === 'pgsql' && $db->getDatabaseName() === 'db_acdf8d_dclubora'
            && $db->transactionLevel() === 0, 'exact standalone PostgreSQL guard');
        $this->expect(app('router')->getRoutes()->match(Request::create('/chat-data/list'))->getActionName()
            === \App\ClubUnio\Http\WebChatController::class, 'web adapter route');
        $overrides = ['cache.default' => 'array', 'session.driver' => 'array', 'session.secure' => true,
            'clubunio-domains.central_hosts' => [...config('clubunio-domains.central_hosts', []), 'web-chat-probe.invalid'],
            'clubunio-domains.hosts' => config('clubunio-domains.hosts', []),
            'clubunio-mobile-push.enabled' => false, 'clubunio-mobile-push.delivery_enabled' => false,
            'clubunio-mobile.ai.enabled' => false, 'clubunio-mobile.public_identity.mail_enabled' => false,
            'clubunio-mobile.keybox.provider_enabled' => false];
        $original = [];
        foreach ($overrides as $key => $value) $original[$key] = config($key);
        config($overrides);
        Http::preventStrayRequests(); Mail::fake(); Queue::fake();
        $app->instance(MalwareScanner::class, new class implements MalwareScanner {
            public function scan(string $bytes): array { throw new \LogicException('Scanner unavailable in synthetic gate'); }
        });
        $app['session']->forgetDrivers();
        $session = $app['session']->driver();
        $session->start();
        $kernel = $app->make(Kernel::class);
        $tenantIds = []; $actorIds = [];
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-web-chat-probe'");
            $db->statement("SET LOCAL lock_timeout TO '3s'");
            $db->statement("SET LOCAL statement_timeout TO '20s'");
            $tenant = Tenant::create(['slug' => 'web-chat-probe-'.Str::lower(Str::random(16)), 'name' => 'Synthetic web chat']);
            $tenantIds[] = (int) $tenant->id;
            $profile = $db->table('clubora.access_profiles')->insertGetId(['tenant_id' => $tenant->id,
                'name' => 'Synthetic chat reader', 'active' => true, 'permissions' => json_encode(['chat.view'])]);
            $actors = [];
            foreach (['admin', 'office', 'profile'] as $role) {
                $actor = new Account;
                $actor->forceFill(['username' => 'web-chat-probe-'.Str::random(24), 'display_name' => 'Synthetic '.$role,
                    'password' => 'not-a-login-secret', 'active' => true, 'api_only' => false])->save();
                $actors[$role] = $actor; $actorIds[] = (int) $actor->id;
                $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                    'role' => $role, 'portal_enabled' => false, 'access_profile_id' => $role === 'profile' ? $profile : null]);
            }
            $context = ['tenant' => (int) $tenant->id, 'revision' => (string) Str::uuid()];
            $host = 'web-chat-probe.invalid';
            $call = function (Account $actor, string $method, string $operation, array $data = [], int $status = 200, bool $csrf = true) use ($kernel, $session, &$context, &$host): array {
                Auth::forgetGuards();
                $session->put(Auth::guard('clubunio')->getName(), $actor->id);
                $session->put('clubunio_context.'.$actor->id, $context);
                $session->save();
                $name = $session->getName();
                $cookie = Crypt::encryptString(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId());
                $data += ['context' => $context];
                $path = $operation === 'page' ? '/chats' : '/chat-data/'.$operation;
                $request = Request::create('https://'.$host.$path, $method, $method === 'GET' ? $data : [],
                    [$name => $cookie], [], ['HTTPS' => 'on', 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'application/json',
                        'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf ? $session->token() : ''],
                    $method === 'GET' ? null : json_encode($data, JSON_THROW_ON_ERROR));
                $scope = isset($context['host']) ? 'bound-domain ' : '';
                $this->stage = $scope.$method.' '.$operation;
                $response = $kernel->handle($request);
                $this->expect($response->getStatusCode() === $status, $scope.$method.' '.$operation.' status '.$status.' actual '.$response->getStatusCode());
                if ($operation === 'page') return ['html' => $response->getContent()];
                $this->expect($response->headers->hasCacheControlDirective('no-store'), $operation.' private response');
                return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            };
            $this->expect(app(ChatAccess::class)->allows($actors['office'], $tenant, 'announce')
                && !app(MobileAccess::class)->admin($actors['office'], $tenant), 'office chat without finance admin');
            foreach (['office', 'profile'] as $role) {
                $html = $call($actors[$role], 'GET', 'page')['html'];
                $dom = new \DOMDocument;
                @$dom->loadHTML($html);
                $root = (new \DOMXPath($dom))->query('//*[@data-cu-chat]')->item(0);
                $this->expect($root instanceof \DOMElement, 'Filament chat root');
                $pageConfig = json_decode($root->getAttribute('data-config'), true, 512, JSON_THROW_ON_ERROR);
                $this->expect(str_ends_with($pageConfig['base'], '/chat-data') && $pageConfig['context'] === $context
                    && $pageConfig['actorId'] === (int) $actors[$role]->id, 'page session transport context');
                $this->expect($pageConfig['capabilities']['send'] === ($role === 'office'), 'page role capabilities');
            }
            $this->expect($db->table('clubora.chat_messages')->where('tenant_id', $tenant->id)->count() === 0
                && $db->table('clubora.chat_changes')->where('tenant_id', $tenant->id)->count() === 0, 'page rendering never sends messages');
            if (!$app->runningUnitTests()) $call($actors['office'], 'POST', 'direct', ['account_id' => $actors['admin']->id], 419, false);
            $list = $call($actors['profile'], 'GET', 'list');
            $this->expect($list['data']['capabilities']['view'] && !$list['data']['capabilities']['send'], 'reader capabilities');
            $call($actors['profile'], 'POST', 'send', [], 403);
            $call($actors['profile'], 'POST', 'react', [], 403);
            $call($actors['profile'], 'GET', 'preview', [], 403);
            $chat = $call($actors['office'], 'POST', 'direct', ['account_id' => $actors['admin']->id])['data']['id'];
            $input = ['chat_id' => $chat, 'client_id' => (string) Str::uuid(), 'text' => 'Synthetic rollback message'];
            $message = $call($actors['office'], 'POST', 'send', $input)['data'];
            $replay = $call($actors['office'], 'POST', 'send', $input)['data'];
            $this->expect($message['id'] === $replay['id'] && $db->table('clubora.chat_messages')->where('tenant_id', $tenant->id)->count() === 1,
                'sender replay inserts exactly one message');
            $call($actors['office'], 'POST', 'send', array_replace($input, ['text' => 'Changed draft']), 409);
            $call($actors['office'], 'GET', 'send', [], 404);
            $call($actors['office'], 'GET', 'conversation', ['chat_id' => PHP_INT_MAX], 404);
            $call($actors['profile'], 'GET', 'conversation', ['chat_id' => $chat], 404);
            $call($actors['office'], 'GET', 'list', ['context' => ['tenant' => $tenant->id, 'revision' => (string) Str::uuid()]], 419);
            $call($actors['admin'], 'GET', 'messages', ['chat_id' => $chat]);
            $delta = $call($actors['admin'], 'GET', 'changes', ['chat_id' => $chat, 'after' => 0]);
            $measuring = true;
            $db->listen(function () use (&$measuring): void { if ($measuring) $this->idleQueries++; });
            try {
                $idle = $call($actors['admin'], 'GET', 'changes', ['chat_id' => $chat, 'after' => $delta['meta']['cursor']]);
            } finally { $measuring = false; }
            $this->expect($idle['data'] === [] && $idle['meta']['cursor'] === $delta['meta']['cursor'], 'idle delta preserves cursor');
            $this->expect($this->idleQueries > 0 && $this->idleQueries <= 30, 'bounded idle SQL budget');
            $call($actors['admin'], 'POST', 'report', ['message_id' => $message['id'], 'reason' => 'Synthetic reason'], 403);
            $db->table('clubora.access_profiles')->where('id', $profile)->update(['active' => false]);
            $call($actors['profile'], 'GET', 'list', [], 403);
            config(['clubunio-domains.hosts' => [...config('clubunio-domains.hosts', []), 'web-chat-bound.invalid' => $tenant->slug]]);
            $host = 'web-chat-bound.invalid';
            $context['host'] = $host;
            $call($actors['office'], 'GET', 'list');
            $call($actors['office'], 'POST', 'direct', ['account_id' => $actors['admin']->id]);
            $this->expect(Http::recorded()->isEmpty() && Mail::sent(Mailable::class)->isEmpty() && Mail::queued(Mailable::class)->isEmpty()
                && Queue::pushedJobs() === [], 'no HTTP mail or queued work');
            $this->expect($db->transactionLevel() === 1, 'outer transaction retained');
        } finally {
            while ($db->transactionLevel() > 0) $db->rollBack();
            config($original);
            Auth::forgetGuards();
            $app['session']->forgetDrivers();
        }
        $this->expect(!$db->table('clubora.tenants')->whereIn('id', $tenantIds)->exists()
            && !$db->table('clubora.accounts')->whereIn('id', $actorIds)->exists(), 'verified fixture rollback');
        return $this->checks;
    }
}

if (defined('CLUBUNIO_WEB_CHAT_INCLUDE_ONLY') || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) return;
$probe = null;
try {
    if (PHP_SAPI !== 'cli' || array_slice($argv, 1) !== ['--synthetic-rollback']) throw new \LogicException('Explicit synthetic rollback required');
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('app.url') !== 'https://dev.clubunio.ch' || !config('clubunio-mobile.enabled')) throw new \LogicException('Exact enabled DEV required');
    $probe = new WebChatProbe;
    $checks = $probe->run($app);
    echo "Web chat: $checks HTTP/security checks; idle SQL $probe->idleQueries; synthetic fixtures rolled back; no providers.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'Web chat gate failed at '.($probe?->stage ?? 'bootstrap').': '.get_class($error).' (private details suppressed).'.PHP_EOL);
    exit(1);
}
