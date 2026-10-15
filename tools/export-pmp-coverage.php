<?php

use App\ClubUnio\Services\PmpInventory;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$inventory = (new PmpInventory)->read(dirname($root).'/phpmaker/svs2026_prerelease_v3.pmp');
$groups = require $root.'/deploy/pmp-coverage-map.php';
$mapping = [];
foreach ($groups as $group) {
    foreach ($group['objects'] as $object) {
        if (isset($mapping[$object])) {
            throw new LogicException('Duplicate coverage object');
        } $mapping[$object] = array_diff_key($group, ['objects' => true]);
    }
}
$unknown = array_diff(array_keys($inventory['tables']), array_keys($mapping));
$removed = array_diff(array_keys($mapping), array_keys($inventory['tables']));
if ($unknown || $removed) {
    throw new LogicException('Mapping drift: '.implode(', ', [...$unknown, ...$removed]));
}
$inventory['coverage'] = $mapping;
$json = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$escape = fn ($value) => str_replace(['|', "\r", "\n"], ['\\|', '', ' '], $value);
$lines = ['# PMP-Abdeckungsmatrix ClubUnio', '', 'Stand15.10.2026, Fachabgleich61. Automatisch aus einer expliziten Metadaten-Allowlist.', '',
    'Quelle: operative PMP, Version'.$inventory['version'].'. SHA256: `'.$inventory['source_sha256'].'`.',
    'Umfang: '.count($inventory['tables']).' Objekte, '.array_sum(array_map(fn ($t) => count($t['fields']), $inventory['tables'])).' Felder, '.count($inventory['relations']).' Beziehungen, '.count($inventory['hooks']).' Eventreferenzen.', '',
    '**Keine Vollstaendigkeitsquote:** Web bedeutet vorhandener Fachweg, nicht identische Felder/SQL oder bestaetigte Fachabnahme. Event-Hashes sind Inventar, kein Nachweis semantischer Gleichheit. Alle individuellen Freigaben bleiben dokumentiert offen.', '',
    'Feldmetadaten inklusive Pflicht/Lookup/Elternabhaengigkeit in pmp-coverage.json. Keine Passwoerter, Defaults, SQL, Eventtexte oder Parameterwerte exportiert.', '',
    '| PMP-Objekt | Felder | Stand | ClubUnio / Nachweis | Abgrenzung |', '|---|---:|---|---|---|'];
foreach ($inventory['tables'] as $name => $table) {
    $m = $mapping[$name];
    $lines[] = '|'.$escape($name).'|'.count($table['fields']).'|'.$m['state'].'|'.$escape($m['target'].'; '.$m['evidence']).'|'.$escape($m['note']).'|';
}
$lines = [...$lines, '', '## Master/Detail und Lookup-Richtung', '', '| PMP | ClubUnio-Einordnung |', '|---|---|'];
foreach ($inventory['relations'] as $r) {
    $lookup = in_array($r['detail'], ['Institut', 'Funktionen', 'StaffQualifikationen', 'TeamSatffFunktion'], true) || ($r['master'] === 'Events' && $r['detail'] === 'Teams');
    $lines[] = '|'.$escape($r['master'].' -> '.$r['detail']).'|'.($lookup ? 'Lookup/Referenz; keine invertierte untergeordnete Schreibpflege' : 'Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten').'|';
}
$markdown = implode("\n", $lines)."\n";
if (in_array('--check', $argv, true)) {
    if (@file_get_contents($root.'/deploy/pmp-coverage.json') !== $json || @file_get_contents($root.'/deploy/CLUBUNIO-PMP-COVERAGE.md') !== $markdown) {
        throw new LogicException('Coverage artifacts stale');
    }
} else {
    file_put_contents($root.'/deploy/pmp-coverage.json', $json);
    file_put_contents($root.'/deploy/CLUBUNIO-PMP-COVERAGE.md', $markdown);
}
echo count($mapping).' objects classified; metadata only; no secrets/event bodies.'.PHP_EOL;
