<?php

// Read-only, authenticated DEV rendering smoke test; no messages or provider calls.
if (PHP_SAPI !== 'cli' || !str_starts_with(getcwd(), '/var/www/svs-laravel/releases/')) {
    exit(1);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$request = Illuminate\Http\Request::create('https://dev.clubunio.ch/support/club/4');
$app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['session.driver' => 'array']);
if (config('view.paths') !== [resource_path('views')]) {
    throw new RuntimeException('Views must use the candidate release');
}
$db = Illuminate\Support\Facades\DB::connection('clubunio');
$db->beginTransaction();
try {
    $db->statement('SET TRANSACTION READ ONLY');
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $actor = App\ClubUnio\Models\Account::where('active', true)->where('api_only', false)
        ->whereHas('tenants', fn ($q) => $q->whereKey($tenant->id)->whereIn('clubunio.tenant_accounts.role', ['admin', 'office']))
        ->firstOrFail();
    Illuminate\Support\Facades\Auth::guard('clubunio')->setUser($actor);
    foreach (['tickets' => 'cs-ticket-list', 'knowledge' => 'cs-knowledge', 'assistant' => 'data-support-chat',
        'create' => 'enctype="multipart/form-data"', 'editorial' => 'name="article_id"'] as $tab => $marker) {
        $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/support/club/'.$tenant->id.'?tab='.$tab);
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200
            || !str_contains($response->getContent(), $tab === 'editorial' ? 'name="audience"' : $marker)) {
            throw new RuntimeException('DEV support tab failed: '.$tab.' HTTP '.$response->getStatusCode());
        }
        if (!str_contains($response->getContent(), 'target="_blank" rel="noopener noreferrer"')) {
            throw new RuntimeException('Public sign-in help must open outside the iframe');
        }
        echo $tab.": HTTP 200 and new workspace rendered\n";
    }
    $request = Illuminate\Http\Request::create('https://dev.clubunio.ch/help/sign-in');
    $response = app(App\ClubUnio\Http\PublicSupportIntake::class)($request);
    if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), 'name="email"')) {
        throw new RuntimeException('DEV public sign-in form failed: HTTP '.$response->getStatusCode());
    }
    echo "Public sign-in form: HTTP 200; transaction rolled back\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $db->rollBack();
}
if (isset($failure)) {
    fwrite(STDERR, get_class($failure).': '.$failure->getMessage().PHP_EOL);
    exit(1);
}
