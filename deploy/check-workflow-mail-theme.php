<?php

use App\ClubUnio\Models\{Account, MessageTemplate, Tenant};
use App\ClubUnio\Services\{ClubUnioMailTheme, TestMailGuard, TestMailService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB, Mail};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CLUBUNIO_BASE') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection('clubunio')->getDatabaseName();
if (!in_array($database, ['clubunio_dev', 'clubunio_stage', 'clubunio_prod'], true)) exit(1);
$tenant = Tenant::where('slug', 'sv-sissach')->sole();
$checks = 0;
foreach (['de', 'en'] as $locale) {
    $title = $locale === 'de' ? 'Spesenbeleg zur Kontrolle' : 'Expense claim for review';
    $body = $locale === 'de' ? 'Synthetischer Spesenbeleg, CHF 12.35. Keine Zahlung.' : 'Synthetic expense claim, CHF 12.35. No payment.';
    $html = app(ClubUnioMailTheme::class)->render($tenant, $title, $body,
        rtrim(config('app.url'), '/').'/expenses', trans('mail_theme.open', [], $locale), $locale);
    if (!str_contains($html, '<html lang="'.$locale.'">') || !str_contains($html, 'CHF 12.35')
        || !str_contains($html, 'Powered by ClubUnio')) throw new LogicException('Theme check failed');
    if ($locale === 'de' && trans('mail_theme.open', [], $locale) !== "Vorgang \u{00F6}ffnen") {
        throw new LogicException('Deployed German mail catalogue is not the expected UTF-8 text');
    }
    if ($locale === 'de') {
        $directory = $root.'/storage/app/private/qa/workflow-mail-20261124';
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        file_put_contents($directory.'/preview.html', $html);
    }
    $checks += 3;
}
echo json_encode(['database' => $database, 'render_checks' => $checks, 'smtp' => false], JSON_THROW_ON_ERROR).PHP_EOL;
if (in_array('--smtp', $argv, true)) {
    if ($database !== 'clubunio_dev') throw new LogicException('SMTP acceptance limited to DEV test sink');
    $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
    $template = MessageTemplate::where('tenant_id', $tenant->id)->where('event_key', 'expense.review')
        ->where('channel', 'email')->where('locale', 'de-CH')->where('active', true)->sole();
    $request = (string)Ramsey\Uuid\Uuid::uuid5(Ramsey\Uuid\Uuid::NAMESPACE_URL, 'clubunio-workflow-mail-theme-utf8-20261124-'.$tenant->id);
    $state = app(TestMailService::class)->sendPreview($actor, $tenant, $template->id, $request);
    echo json_encode(['smtp_test_sink' => TestMailGuard::RECIPIENT, 'status' => $state], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($state !== 'accepted') exit(1);
}
