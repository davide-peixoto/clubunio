<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LanguageCatalogIntegrityTest extends TestCase
{
    public function test_enabled_catalogues_cover_backlog_and_all_merged_parts(): void
    {
        $root = dirname(__DIR__, 2);
        $config = require $root.'/config/clubunio-languages.php';
        $english = json_decode(file_get_contents($root.'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $backlog = json_decode(file_get_contents($root.'/lang/legacy-label-backlog.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], array_values(array_diff($backlog, array_keys($english))));
        foreach (glob($root.'/lang/parts/*.en.json') as $part) {
            foreach (json_decode(file_get_contents($part), true, 512, JSON_THROW_ON_ERROR) as $key => $value) {
                $this->assertSame($value, $english[$key] ?? null, basename($part).': '.$key);
            }
        }
        foreach (array_keys($config['enabled']) as $locale) {
            $catalog = json_decode(file_get_contents($root.'/lang/'.$locale.'.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(array_keys($english), array_keys($catalog));
            foreach ($catalog as $key => $value) {
                $this->assertIsString($value, $locale.': '.$key);
                $this->assertNotSame('', trim($value));
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
                preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
                sort($expected[0]); sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale.': '.$key);
            }
        }
    }

    public function test_php_business_sources_remain_syntactically_valid(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['app/ClubUnio', 'app/Livewire', 'app/Providers'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') { continue; }
                try { token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE); }
                catch (\ParseError $error) { $this->fail($file->getPathname().': '.$error->getMessage()); }
                $this->addToAssertionCount(1);
            }
        }
    }
}
