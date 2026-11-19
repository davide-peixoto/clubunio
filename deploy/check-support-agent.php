<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = config('clubunio-support');
echo json_encode([
    'enabled' => $config['enabled'],
    'triage_enabled' => $config['triage_enabled'],
    'key_configured' => !empty($config['openai_key']),
    'project_configured' => !empty($config['openai_project']),
    'model_configured' => !empty($config['model']),
]).PHP_EOL;
$answer = app(App\ClubUnio\Services\SupportGuide::class)->answer('How do I submit an expense?', 'en');
echo json_encode(['synthetic_answer' => !empty($answer['text']), 'source' => $answer['source'], 'provider_generated' => $answer['ai']]).PHP_EOL;
