<?php

// Installs reviewed support files while preserving instance-specific layout code.
if (PHP_SAPI !== 'cli' || count($argv) !== 3
    || !in_array(getcwd(), ['/home/runcloud/webapps/ClubUnioStage', '/home/runcloud/webapps/ClubUnioProd'], true)) exit(1);
[$script, $source, $mode] = $argv;
$files = [
    'app/ClubUnio/Http/SupportCenterController.php',
    'resources/views/clubunio/support/center.blade.php',
    'resources/views/clubunio/support/assistant.blade.php',
    'resources/views/clubunio/support/launcher.blade.php',
    'resources/views/clubunio/support/platform-center.blade.php',
    'resources/views/clubunio/support/login-help.blade.php',
    'resources/views/clubunio/support/intake-inbox.blade.php',
    'public/js/clubunio-support.js', 'public/js/clubunio-help-widget.js',
    'public/css/clubunio-help-widget.css', 'public/css/clubunio-support-workspace.css',
    'lang/de/support.php', 'lang/en/support.php',
];
$integrations = [
    'app/Providers/Filament/ClubUnioPanelProvider.php',
    'resources/views/clubunio/portal/layout.blade.php',
    'resources/views/clubunio/platform/layout.blade.php',
];
if ($mode === 'files') {
    echo implode("\n", array_merge($files, $integrations))."\n";
    exit;
}
if ($mode !== 'apply') exit(1);
$write = function (string $path, string $content): void {
    if (!is_dir(dirname($path))) throw new RuntimeException('Missing directory: '.$path);
    if (file_put_contents($path, $content) === false) throw new RuntimeException('Could not write '.$path);
    chown($path, fileowner('artisan'));
    chgrp($path, filegroup('artisan'));
    chmod($path, 0644);
};
$planned = [];
foreach ($files as $file) {
    $contents = file_get_contents($source.'/'.$file);
    if ($contents === false) throw new RuntimeException('Missing reviewed source '.$file);
    $planned[$file] = str_replace('clubora.', 'clubunio.', $contents);
}
$provider = $integrations[0];
$contents = file_get_contents($provider);
if (!str_contains($contents, "view('clubunio.support.launcher')")) {
    $anchor = "->renderHook('panels::footer', fn () => view('clubunio.brand.panel-attribution'))";
    if (substr_count($contents, $anchor) !== 1) throw new RuntimeException('Unknown panel integration');
    $contents = str_replace($anchor, $anchor."\n            ->renderHook('panels::body.end', fn () => auth('clubunio')->check() ? view('clubunio.support.launcher') : '')", $contents);
}
$planned[$provider] = $contents;
$portal = $integrations[1];
$contents = file_get_contents($portal);
$old = '<script src="{{ asset(\'js/clubunio-help-widget.js\') }}"></script>';
$new = <<<'BLADE'
<script src="{{ asset('js/clubunio-help-widget.js').'?v=workspace-1' }}"
            data-close-label="{{ trans('support.close_panel') }}"
            data-support-url="{{ isset($tenant) ? route('clubunio.support.center', ['club' => $tenant->id]) : url('/help') }}"
            data-support-label="{{ trans('support.title') }}"></script>
BLADE;
if (substr_count($contents, $old) === 1) {
    $contents = str_replace($old, $new, $contents);
} elseif (!str_contains($contents, '?v=workspace-1')) {
    throw new RuntimeException('Unknown portal support integration');
}
$planned[$portal] = $contents;
$platform = $integrations[2];
$contents = file_get_contents($platform);
// Platform support deliberately forbids embedding; keep its existing top-level nav.
$contents = str_replace("@isset(\$actor)\n@include('clubunio.support.launcher', ['supportPlatform' => true])\n@endisset\n</body>", '</body>', $contents);
$planned[$platform] = $contents;
foreach ($planned as $path => $contents) $write($path, $contents);
echo count($planned)." scoped support files installed\n";
