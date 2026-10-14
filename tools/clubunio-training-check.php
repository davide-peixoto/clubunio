<?php

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TrainingPlanAi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
foreach (['OPENAI_API_KEY', 'OPENAI_TRAINING_VECTOR_STORE_ID', 'OPENAI_TRAINING_MODEL'] as $key) {
    $row = Setting::where('tenant_id', $tenant->id)->where('key', $key)->first();
    echo $key.': '.($row && trim(Crypt::decryptString($row->encrypted_value)) !== '' ? 'configured' : 'missing').PHP_EOL;
}
if (in_array('--live-test', $argv, true)) {
    // Synthetic coaching context only; no member IDs, names or medical information.
    try {
        $plan = app(TrainingPlanAi::class)->generate($tenant, [
            'teamName' => 'B-Junioren', 'durationMinutes' => 60, 'playerCount' => 18,
            'goal' => 'Passspiel und Freilaufen', 'fieldSize' => 'Halbes Spielfeld', 'material' => 'Baelle, Huetchen, Leibchen, zwei Tore',
        ]);
        echo 'Live plan validated: '.count($plan['exercises']).' exercises'.PHP_EOL;
    } catch (Throwable $e) {
        echo 'Live plan failed: status '.(int) $e->getCode().', type '.get_class($e).PHP_EOL;
        exit(1);
    }
}
