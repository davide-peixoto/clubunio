<?php

// Merge independently maintained translation batches, refusing conflicting wording.
$root = dirname(__DIR__);
$path = $root.'/lang/en.json';
$catalog = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$origins = array_fill_keys(array_keys($catalog), 'en.json');
$conflicts = [];
foreach (glob($root.'/lang/parts/*.en.json') as $part) {
    $entries = json_decode(file_get_contents($part), true, 512, JSON_THROW_ON_ERROR);
    foreach ($entries as $key => $value) {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Empty translation in '.basename($part).': '.$key);
        }
        if (isset($catalog[$key]) && $catalog[$key] !== $value) {
            $conflicts[] = $key.' ['.$origins[$key].': '.$catalog[$key].' / '.basename($part).': '.$value.']';
            continue;
        }
        $catalog[$key] = $value;
        $origins[$key] = basename($part);
    }
}
if ($conflicts) {
    fwrite(STDERR, 'Translation conflicts:'.PHP_EOL.implode(PHP_EOL, $conflicts).PHP_EOL);
    exit(1);
}
ksort($catalog, SORT_STRING);
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
file_put_contents($path, json_encode($catalog, $flags).PHP_EOL);
$german = array_combine(array_keys($catalog), array_keys($catalog));
file_put_contents($root.'/lang/de.json', json_encode($german, $flags).PHP_EOL);
echo count($catalog).' translation keys merged.'.PHP_EOL;
