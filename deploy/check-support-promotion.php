<?php

if (PHP_SAPI !== 'cli' || !in_array(getcwd(), ['/home/runcloud/webapps/ClubUnioStage', '/home/runcloud/webapps/ClubUnioProd'], true)) exit(1);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://localhost'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$instance = basename(getcwd()) === 'ClubUnioStage' ? 'stage' : 'prod';
$origin = 'https://'.($instance === 'stage' ? 'stage.clubunio.com' : 'app.clubunio.com');
if (config('database.connections.clubunio.database') !== 'clubunio_'.$instance
    || config('view.paths') !== [resource_path('views')]) throw new RuntimeException('Instance mismatch');
config(['session.driver' => 'array']);
Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$db->beginTransaction();
$failed = null;
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $actor = App\ClubUnio\Models\Account::where('active', true)->where('api_only', false)
        ->whereHas('tenants', fn ($q) => $q->whereKey($tenant->id)->whereIn('clubunio.tenant_accounts.role', ['admin', 'office']))
        ->firstOrFail();
    Illuminate\Support\Facades\Auth::guard('clubunio')->setUser($actor);
    $render = function (string $path, string $marker, ?int $club = null) use ($app, $origin): void {
        $request = Illuminate\Http\Request::create($origin.$path);
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn ($guard = null) => Illuminate\Support\Facades\Auth::guard($guard)->user());
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $app->instance('request', $request);
        $response = app(App\ClubUnio\Http\SupportCenterController::class)($request, $club);
        if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), $marker)) {
            throw new RuntimeException('Failed support rendering: '.$path);
        }
        echo $path.": HTTP 200\n";
    };
    foreach (['tickets' => 'cs-ticket-list', 'knowledge' => 'cs-knowledge', 'assistant' => 'data-support-chat',
        'create' => 'enctype="multipart/form-data"', 'editorial' => 'name="audience"'] as $tab => $marker) {
        $render('/support/club/'.$tenant->id.'?embed=1&tab='.$tab, $marker, (int) $tenant->id);
    }
    $request = Illuminate\Http\Request::create($origin.'/help/sign-in');
    $app->instance('request', $request);
    $response = app(App\ClubUnio\Http\PublicSupportIntake::class)($request);
    if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), 'name="email"')) {
        throw new RuntimeException('Sign-in help failed');
    }
    $operator = App\ClubUnio\Models\Account::where('active', true)->where('api_only', false)
        ->whereIn('id', $db->table('clubunio.platform_admins')->where('active', true)->select('account_id'))->first();
    if ($operator) {
        Illuminate\Support\Facades\Auth::guard('platform')->setUser($operator);
        Illuminate\Support\Facades\Auth::guard('clubunio')->setUser($operator);
        foreach (['tickets' => 'sp-ticket-list', 'knowledge' => 'sp-reading', 'assistant' => 'data-support-chat'] as $tab => $marker) {
            $render('/platform/support?tab='.$tab, $marker);
        }
    } else {
        echo "No active platform operator: platform browser acceptance remains separate\n";
    }
    echo "Sign-in help OK; no POST, provider call or DB change\n";
} catch (Throwable $error) {
    $failed = $error;
} finally {
    $db->rollBack();
}
if ($failed) {
    fwrite(STDERR, $failed->getMessage().PHP_EOL);
    exit(1);
}
