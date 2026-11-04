<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LanguageCatalogIntegrityTest extends TestCase
{
    public function test_navigation_review_labels_and_accessibility_use_english_catalogue(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = json_decode(file_get_contents($root.'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('App banners', $catalog['Appbanner']);
        $this->assertSame('Personal keyboxes', $catalog['Persönliche Schlüsselboxen']);
        $this->assertSame('Personal keybox', $catalog['Persönliche Schlüsselbox']);
        $this->assertSame('Submenu :label', $catalog['Untermenü :label']);
        $this->assertSame('Expand or collapse submenu', $catalog['Untermenü auf- oder zuklappen']);
        $view = file_get_contents($root.'/resources/views/vendor/filament-panels/components/sidebar/item.blade.php');
        $this->assertStringContainsString("__('Untermenü :label',", $view);
        $this->assertStringContainsString("__('Untermenü auf- oder zuklappen')", $view);
    }

    public function test_embedded_views_compile_and_keep_weather_logic_and_user_captions_raw(): void
    {
        $root = dirname(__DIR__, 2);
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem, sys_get_temp_dir());
        $compiler->withoutComponentTags();
        foreach (glob($root.'/resources/views/livewire/clubunio/*.blade.php') as $file) {
            token_get_all($compiler->compileString(file_get_contents($file)), TOKEN_PARSE);
            $this->addToAssertionCount(1);
        }
        $source = file_get_contents($root.'/resources/views/livewire/clubunio/signage-kiosk-feed.blade.php');
        $this->assertStringContainsString("['Klar','Überwiegend klar']", $source);
        $this->assertStringContainsString("\$post['caption']?:__('Instagram-Beitrag')", $source);
        $source = file_get_contents($root.'/resources/views/livewire/clubunio/planning-admin-table.blade.php');
        $this->assertStringContainsString("__('Teamplanung') : __('Trainingspläne')", $source);
    }

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
