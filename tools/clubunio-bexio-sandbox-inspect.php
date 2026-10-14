<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BexioConnectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora') {
    throw new LogicException('Unexpected database');
}
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
try {
    $service = app(BexioConnectionService::class);
    $connection = $service->connection($tenant);
    echo 'Connected at: '.($connection['connected_at'] ?? 'unknown').PHP_EOL;
    $token = $service->accessToken($tenant);
    foreach (['company_profile', 'contact', 'kb_invoice'] as $endpoint) {
        $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->get('https://api.bexio.com/2.0/'.$endpoint, $endpoint === 'company_profile' ? [] : ['limit' => 1]);
        echo $endpoint.' HTTP '.$response->status().PHP_EOL;
        if (! $response->successful()) {
            continue;
        }
        $data = $response->json();
        if (! is_array($data)) {
            echo 'Unexpected response shape'.PHP_EOL;

            continue;
        }
        if ($endpoint === 'company_profile') {
            foreach ($data as $profile) {
                if (is_array($profile)) {
                    echo json_encode(array_intersect_key($profile, array_flip(['id', 'name', 'company_name'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
                }
            }
        } else {
            echo 'Sample row count (limit 1): '.count($data).PHP_EOL;
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Connection check failed; code '.(int) $error->getCode().PHP_EOL);
    exit(1);
}
