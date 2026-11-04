<?php

// Validate complete catalogues for every enabled language. --sync-de mirrors source keys.
$root = dirname(__DIR__);
$config = require $root.'/config/clubunio-languages.php';
$source = json_decode(file_get_contents($root.'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
if (in_array('--sync-de', $argv, true)) {
    file_put_contents($root.'/lang/de.json', json_encode(array_combine(array_keys($source), array_keys($source)), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
}
$errors = [];
$literalLabels = [];
foreach (['app/ClubUnio','app/Livewire','app/Providers','resources/views'] as $folder) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') { continue; }
        $content = file_get_contents($file->getPathname());
        // Blade expressions are inline HTML to PHP's tokenizer; inspect their literal calls too.
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            preg_match_all('/(?:__|@lang)\(\s*((?:\x27(?:\\\\.|[^\x27\\\\])*\x27)|(?:"(?:\\\\.|[^"\\\\])*"))/', $content, $calls);
            foreach ($calls[1] as $literal) {
                $key = substr($literal, 1, -1);
                $key = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $key);
                if (! isset($source[$key]) && $key !== '' && ! str_contains($key, '::')) { $literalLabels[$key] = true; }
            }
        }
        $tokens = array_values(array_filter(token_get_all($content),fn ($t)=>! is_array($t) || ! in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
        foreach ($tokens as $i=>$token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1],['__','label','tooltip','helperText','placeholder'],true)) { continue; }
            $literal = $tokens[$i+2] ?? null;
            if (($tokens[$i+1] ?? '') !== '(' || ! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
            // LookupTranslations::label(table, row) is not a component UI label.
            if ($token[1] === 'label' && ($tokens[$i+3] ?? '') === ',') { continue; }
            $key = substr($literal[1],1,-1);
            $key = str_replace(["\\'",'\\\\'],["'",'\\'],$key);
            if (! isset($source[$key]) && $key !== '' && ! str_contains($key,'::')) { $literalLabels[$key]=true; }
        }
    }
}
$missing = array_keys($literalLabels);
sort($missing);
$baselinePath = $root.'/lang/legacy-label-backlog.json';
if (in_array('--baseline', $argv, true)) {
    file_put_contents($baselinePath,json_encode($missing,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
}
// The historical backlog is now an inventory, never an exemption from coverage.
foreach ($missing as $key) { $errors[] = 'Untranslated UI text: '.$key; }
foreach ($config['enabled'] as $locale => $label) {
    $path = $root.'/lang/'.$locale.'.json';
    $catalog = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    foreach ($source as $key => $english) {
        $value = $catalog[$key] ?? null;
        if (! is_string($value) || trim($value) === '') { $errors[] = "$locale: missing $key"; continue; }
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
        sort($expected[0]); sort($actual[0]);
        if ($expected[0] !== $actual[0]) { $errors[] = "$locale: placeholder mismatch $key"; }
    }
    foreach (array_diff(array_keys($catalog), array_keys($source)) as $key) { $errors[] = "$locale: unmatched $key"; }
}
$flatten = function (array $values, string $prefix = '') use (&$flatten): array {
    $result = [];
    foreach ($values as $key => $value) {
        $path = $prefix.$key;
        if (is_array($value)) { $result += $flatten($value, $path.'.'); }
        else { $result[$path] = $value; }
    }
    return $result;
};
foreach (glob($root.'/lang/en/*.php') as $group) {
    $expectedGroup = $flatten(require $group);
    foreach (array_keys($config['enabled']) as $locale) {
        $path = $root.'/lang/'.$locale.'/'.basename($group);
        $actualGroup = is_file($path) ? $flatten(require $path) : [];
        if (array_diff_key($expectedGroup, $actualGroup) || array_diff_key($actualGroup, $expectedGroup)) {
            $errors[] = $locale.': PHP group keys differ: '.basename($group);
        }
        foreach ($expectedGroup as $key => $english) {
            $value = $actualGroup[$key] ?? null;
            if (! is_string($value) || trim($value) === '') { $errors[] = "$locale: missing group text $key"; continue; }
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $english, $expected);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
            sort($expected[0]); sort($actual[0]);
            if ($expected[0] !== $actual[0]) { $errors[] = "$locale: group placeholder mismatch $key"; }
        }
    }
}
if ($errors) { fwrite(STDERR, implode(PHP_EOL,$errors).PHP_EOL); exit(1); }
echo count($source).' keys verified in '.implode(', ',array_keys($config['enabled'])).PHP_EOL;
echo count($missing).' existing literal UI texts remain in the documented translation backlog.'.PHP_EOL;
