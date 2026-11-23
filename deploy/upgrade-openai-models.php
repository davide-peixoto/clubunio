<?php

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Services\OpenAiModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': OpenAI model migration failed'.PHP_EOL);
    if (get_class($error) === RuntimeException::class) fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
$database = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($database, ['clubunio_dev', 'clubunio_stage', 'clubunio_prod'], true)
    || DB::connection('clubunio')->getDatabaseName() !== $database) {
    throw new RuntimeException('Explicit matching ClubUnio database required');
}
$mode = $argv[2] ?? '--inspect';
if (!in_array($mode, ['--inspect', '--apply', '--restore'], true)) {
    throw new RuntimeException('Unsupported model migration mode');
}
$directory = storage_path('app/private/openai-model-upgrades');
if ($mode === '--restore') {
    $path = realpath($argv[3] ?? '');
    if (!$path || dirname($path) !== realpath($directory)) {
        throw new RuntimeException('Private model backup required');
    }
    $backup = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (($backup['database'] ?? null) !== $database) throw new RuntimeException('Backup database mismatch');
    DB::connection('clubunio')->transaction(function () use ($backup) {
        foreach ($backup['rows'] as $before) {
            $row = Setting::whereKey($before['id'])->where('tenant_id', $before['tenant_id'])
                ->where('key', 'OPENAI_TRAINING_MODEL')->lockForUpdate()->firstOrFail();
            if (Crypt::decryptString($row->encrypted_value) !== OpenAiModels::TRAINING) {
                throw new RuntimeException('Model changed after migration; manual review required');
            }
            $row->forceFill(['encrypted_value' => $before['encrypted_value']])->save();
        }
    });
    echo "Training model values restored; activation flags unchanged.\n";
    exit(0);
}
$count = DB::connection('clubunio')->transaction(function () use ($database, $mode, $directory) {
    $rows = Setting::where('key', 'OPENAI_TRAINING_MODEL')->lockForUpdate()->get()
        ->filter(fn ($row) => trim(Crypt::decryptString($row->encrypted_value)) !== OpenAiModels::TRAINING);
    if ($mode !== '--apply' || $rows->isEmpty()) return $rows->count();
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Backup directory unavailable');
    $path = $directory.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    $backup = ['database' => $database, 'target' => OpenAiModels::TRAINING, 'rows' => $rows->map(fn ($row) => [
        'id' => $row->id, 'tenant_id' => $row->tenant_id, 'encrypted_value' => $row->encrypted_value,
    ])->values()->all()];
    if (file_put_contents($path, json_encode($backup, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('Private backup failed');
    }
    foreach ($rows as $row) {
        $row->forceFill(['encrypted_value' => Crypt::encryptString(OpenAiModels::TRAINING)])->save();
    }
    echo 'Private rollback: '.$path.PHP_EOL;
    return $rows->count();
});
echo json_encode(['database' => $database, 'mode' => $mode, 'training_model' => OpenAiModels::TRAINING,
    'changed_or_pending' => $count, 'activation_flags_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
