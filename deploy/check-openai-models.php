<?php

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Services\OpenAiModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': OpenAI model check failed'.PHP_EOL);
    if (get_class($error) === RuntimeException::class) fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (PHP_SAPI !== 'cli') exit(1);
$config = ['assistant' => config('clubunio-assistant.model'), 'mobile' => config('clubunio-mobile.ai.openai.model'),
    'support' => config('clubunio-support.model')];
foreach ($config as $model) {
    if (!OpenAiModels::supports($model)) throw new RuntimeException('Unsupported configured OpenAI model');
}
$rows = Setting::where('key', 'OPENAI_TRAINING_MODEL')->get()->map(fn ($row) => [
    'tenant_id' => $row->tenant_id, 'model' => trim(Crypt::decryptString($row->encrypted_value)), 'enabled' => $row->enabled,
])->all();
echo json_encode(['database' => DB::connection('clubunio')->getDatabaseName(), 'models' => $config, 'training' => $rows,
    'assistant_provider_enabled' => config('clubunio-assistant.provider_enabled'),
    'mobile_ai_enabled' => config('clubunio-mobile.ai.enabled'),
    'mobile_training_enabled' => config('clubunio-mobile.training_ai.enabled'),
    'mobile_training_allowed_models' => config('clubunio-mobile.training_ai.models')], JSON_THROW_ON_ERROR).PHP_EOL;
if (!in_array('--live-synthetic', $argv, true)) exit(0);
// No club/member data, tools or automatic retries; project access is tested separately
// from feature activation and never enables a disabled feature.
$key = config('clubunio-assistant.key') ?: config('clubunio-mobile.ai.openai.key') ?: config('clubunio-support.openai_key');
if (!$key) {
    $row = Setting::where('key', 'OPENAI_API_KEY')->where('enabled', true)->first();
    $key = $row ? trim(Crypt::decryptString($row->encrypted_value)) : null;
}
if (!$key) {
    echo json_encode(['synthetic_json' => 'not_run', 'reason' => 'no_existing_key'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}
foreach ([OpenAiModels::TEXT, OpenAiModels::BACKOFFICE, OpenAiModels::COMPLEX] as $model) {
    $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(45)->withoutRedirecting()
        ->post('https://api.openai.com/v1/responses', [
            'model' => $model, 'store' => false, 'max_output_tokens' => 512,
            'reasoning' => OpenAiModels::reasoning($model),
            'input' => 'Synthetic integration check. Return the JSON object with ok set to true. No tools.',
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'model_check', 'strict' => true,
                'schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']],
                    'required' => ['ok'], 'additionalProperties' => false]]],
        ]);
    $text = collect($response->json('output', []))->flatMap(fn ($item) => $item['content'] ?? [])
        ->where('type', 'output_text')->pluck('text')->implode('');
    if (!$response->successful() || $response->json('status') !== 'completed' || json_decode($text, true) !== ['ok' => true]) {
        throw new RuntimeException('Synthetic OpenAI check failed: '.$model.' HTTP '.$response->status());
    }
    echo json_encode(['model' => $model, 'synthetic_json' => 'passed', 'usage' => $response->json('usage')], JSON_THROW_ON_ERROR).PHP_EOL;
}
