<?php

// Public authored help only; no bootstrap, account, environment or database access.
$root = dirname(__DIR__);
$guides = [];
foreach (['de', 'en'] as $locale) {
    foreach (['app', 'web'] as $surface) {
        $items = require $root.'/lang/'.$locale.'/support-'.$surface.'-guides.php';
        if (is_file($extra = $root.'/lang/'.$locale.'/support-'.$surface.'-extra.php')) $items += require $extra;
        $guides[$locale][$surface] = $items;
    }
}
echo json_encode($guides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
