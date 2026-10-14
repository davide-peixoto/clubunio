<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (config('app.url') !== 'https://dev.clubunio.ch') {
        throw new RuntimeException('APP_URL mismatch');
    }
    $tenant = App\ClubUnio\Models\Tenant::where('slug', 'sv-sissach')->firstOrFail();
    $token = app(App\ClubUnio\Services\BexioConnectionService::class)->accessToken($tenant);
    app(App\ClubUnio\Services\BexioSandboxClient::class)->verify($token);
    echo "APP_URL OK; Bexio Sandbox SVS Api Test (ID 1) verified; no business writes.\n";
    echo route('clubunio.bexio.callback')."\n";
} catch (Throwable $error) {
    // Deliberately omit exception messages which could contain HTTP credentials.
    fwrite(STDERR, 'Sandbox verification failed: '.get_class($error).' code '.(int) $error->getCode()."\n");
    exit(1);
}
