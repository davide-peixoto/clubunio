<?php

if (PHP_SAPI !== 'cli') { exit(1); }
$base = getenv('CLUBUNIO_BASE');
if (! in_array($base, ['/home/runcloud/webapps/ClubUnioStage', '/home/runcloud/webapps/ClubUnioProd'], true)) { exit(1); }
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! str_contains(file_get_contents($base.'/app/Providers/Filament/ClubUnioPanelProvider.php'), 'language-header-v4')) {
    throw new LogicException('Missing stylesheet cache version');
}
$app['blade.compiler']->compile($base.'/resources/views/clubunio/language-switcher.blade.php');
foreach (['de', 'en'] as $locale) {
    $app->setLocale($locale);
    $html = view('clubunio.language-switcher')->render();
    foreach (['clubunio-language-switcher.css?v=2', 'cu-language-switcher-icon', 'cu-language-switcher-chevron', 'cu-language-switcher-compact',
        'name="_token"', 'method="POST"', 'this.form.requestSubmit()', 'aria-label="'.__('Sprache').'"', 'name="locale"'] as $needle) {
        if (! str_contains($html, $needle)) { throw new LogicException('Language header missing '.$needle); }
    }
}
echo "LANGUAGE_HEADER_OK: DE/EN, POST/CSRF, native select, icons and versioned CSS\n";
