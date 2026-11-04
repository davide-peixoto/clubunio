<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

class ViewTranslationsTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function catalog(): array
    {
        return json_decode(file_get_contents($this->root().'/lang/parts/views.en.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function views(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/resources/views/clubunio'));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                yield $file->getPathname() => file_get_contents($file->getPathname());
            }
        }
    }

    private function compiler(): BladeCompiler
    {
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        // Validate Blade/PHP without booting providers, resolving components or accessing a database.
        $compiler->withoutComponentTags();

        return $compiler;
    }

    public function test_all_view_expressions_compile_and_html_language_follows_locale(): void
    {
        $compiler = $this->compiler();
        foreach ($this->views() as $path => $source) {
            try {
                token_get_all($compiler->compileString($source), TOKEN_PARSE);
            } catch (\Throwable $error) {
                $this->fail($path.': '.$error->getMessage());
            }
            $this->assertDoesNotMatchRegularExpression('/<html\b[^>]*lang="de(?:-CH)?"/i', $source, $path);
            $this->assertStringNotContainsString("->locale('de_CH')", $source, $path);
        }
    }

    public function test_accessible_labels_tooltips_and_confirmations_are_translatable(): void
    {
        foreach ($this->views() as $path => $source) {
            preg_match_all('/(?<![:\w-])(?:aria-label|aria-description|title|alt|label|tooltip|placeholder|heading|wire:confirm)="([^"]*)"/u', $source, $matches);
            foreach ($matches[1] as $value) {
                if (preg_match('/[\p{L}]/u', $value)) {
                    $this->assertTrue(str_contains($value, '{{') || str_contains($value, '@js('), $path.': '.$value);
                    $literal = preg_replace('/\{\{.*?\}\}/s', '', $value);
                    $this->assertDoesNotMatchRegularExpression('/[\p{L}]/u', $literal, $path.': '.$value);
                }
            }
        }
        $absences = file_get_contents($this->root().'/resources/views/clubunio/planning/absences.blade.php');
        $this->assertStringContainsString("confirm(@js(__('Diese Absenz löschen?')))", $absences);
    }

    public function test_view_catalog_preserves_placeholders_and_agrees_with_other_catalogs(): void
    {
        $catalog = $this->catalog();
        foreach ($catalog as $key => $value) {
            $this->assertNotSame('', trim($value), $key);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $key, $expected);
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $value, $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
        }
        foreach (array_merge([$this->root().'/lang/en.json'], glob($this->root().'/lang/parts/*.en.json')) as $path) {
            $other = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach (array_intersect_key($other, $catalog) as $key => $value) {
                $this->assertSame($value, $catalog[$key], basename($path).': '.$key);
            }
        }
    }

    public function test_document_pdf_translates_chrome_but_never_user_content(): void
    {
        $previous = Container::getInstance();
        $app = new Application($this->root());
        $app->instance('config', new Repository(['app' => ['locale' => 'en']]));
        $loader = new ArrayLoader;
        $loader->addMessages('en', '*', $this->catalog());
        $app->instance('translator', new Translator($loader, 'en'));
        $snapshot = [
            'club' => 'Speichern', 'member_name' => '<script>untrusted</script>',
            'values' => ['Team.Name' => 'Mannschaft', 'Dokument.Datum' => '2026-11-04'],
            'definition' => ['title' => 'Materialübergabe', 'text' => 'Bemerkungen', 'signature_required' => true,
                'items' => [['quantity' => 1, 'price_cents' => 200, 'description' => 'Garderobe']]],
        ];
        try {
            $__env = new class {
                use \Illuminate\View\Concerns\ManagesLoops;
            };
            $compiled = $this->compiler()->compileString(file_get_contents($this->root().'/resources/views/clubunio/document-series/pdf.blade.php'));
            ob_start();
            try {
                eval('?>'.$compiled);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString('lang="en"', $html);
            $this->assertStringContainsString('Equipment value CHF', $html);
            $this->assertStringContainsString('Signature on receipt:', $html);
            foreach (['Speichern', 'Mannschaft', 'Materialübergabe', 'Bemerkungen', 'Garderobe'] as $userText) {
                $this->assertStringContainsString($userText, $html);
            }
            $this->assertStringContainsString('&lt;script&gt;untrusted&lt;/script&gt;', $html);
            $this->assertStringNotContainsString('<script>untrusted</script>', $html);
            $app['config']->set('app.locale', 'de');
            $app['translator']->setLocale('de');
            ob_start();
            try {
                eval('?>'.$compiled);
                $german = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString('lang="de"', $german);
            $this->assertStringContainsString('Materialwert CHF', $german);
            $this->assertStringContainsString('Unterschrift bei Übernahme:', $german);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_signage_uses_board_locale_and_contextual_labels(): void
    {
        $board = file_get_contents($this->root().'/resources/views/clubunio/signage-board.blade.php');
        $script = file_get_contents($this->root().'/public/js/clubunio-signage.js');
        $this->assertStringContainsString('data-locale="{{', $board);
        $this->assertStringContainsString("__('Gastmannschaft')", $board);
        $this->assertStringContainsString('board.dataset.locale', $script);
        $this->assertStringContainsString('new Intl.DateTimeFormat(locale,', $script);
        $this->assertStringContainsString("__('Spielrunde')", file_get_contents($this->root().'/resources/views/clubunio/portal/expense-create.blade.php'));
    }

    public function test_every_literal_view_translation_has_an_english_mapping(): void
    {
        $catalog = $this->catalog() + json_decode(file_get_contents($this->root().'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->views() as $path => $source) {
            preg_match_all("/__\\(\\s*'((?:\\\\.|[^'\\\\])*)'/", $source, $matches);
            foreach ($matches[1] as $literal) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $literal);
                $this->assertArrayHasKey($key, $catalog, $path.': '.$key);
            }
        }
    }

    private function renderPreview(string $view, array $data, string $locale = 'en', ?string $source = null): string
    {
        $previous = Container::getInstance();
        $app = new Application($this->root());
        $app->instance('config', new Repository([
            'app' => ['locale' => $locale],
            'clubunio-languages' => ['enabled' => ['de' => 'Deutsch', 'en' => 'English']],
            'clubunio-lookups' => require $this->root().'/config/clubunio-lookups.php',
        ]));
        $lookups = new \App\ClubUnio\Services\LookupTranslations;
        // No schema reads or database queries in these isolated rendering checks.
        (new \ReflectionProperty($lookups, 'available'))->setValue($lookups, false);
        $app->instance(\App\ClubUnio\Services\LookupTranslations::class, $lookups);
        $loader = new ArrayLoader;
        $loader->addMessages('en', '*', $this->catalog());
        $app->instance('translator', new Translator($loader, $locale));
        $app->instance('request', new class {
            public function old($key, $default = null) { return $default; }
        });
        $__env = new class {
            use \Illuminate\View\Concerns\ManagesLoops;
        };
        extract($data, EXTR_SKIP);
        try {
            $compiled = $this->compiler()->compileString($source ?? file_get_contents($this->root().'/resources/views/clubunio/'.$view.'.blade.php'));
            ob_start();
            try {
                eval('?>'.$compiled);

                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_expense_preview_shows_every_language_and_channel_without_translating_content(): void
    {
        $message = fn ($title) => ['title' => $title, 'body' => 'Speichern <b>user</b>', 'revision' => 17];
        $plan = ['recipients' => [['name' => 'Mannschaft', 'role' => 'trainer']],
            'messages' => ['email' => $message('FIRST ONLY')],
            'variants' => ['de' => ['email' => $message('DE email'), 'push' => $message('DE push')],
                'en' => ['email' => $message('EN email'), 'push' => null]]];
        $html = $this->renderPreview('messages/expense-preview', compact('plan'));
        foreach (['DE email', 'DE push', 'EN email', 'lang="de"', 'lang="en"', 'Deutsch', 'English', 'Mannschaft', 'trainer'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('FIRST ONLY', $html);
        $this->assertSame(3, substr_count($html, 'Speichern &lt;b&gt;user&lt;/b&gt;'));
        $this->assertStringContainsString(e($this->catalog()['Vorlage fehlt oder ist deaktiviert.']), $html);
        unset($plan['variants']);
        $fallback = $this->renderPreview('messages/expense-preview', compact('plan'), 'de');
        $this->assertStringContainsString('FIRST ONLY', $fallback);
        $this->assertStringContainsString('lang="de"', $fallback);
    }

    public function test_announcement_preview_shows_all_variants_and_preserves_recipient_names(): void
    {
        $data = ['count' => 2, 'names' => ['Speichern', '<script>name</script>'], 'title' => 'FIRST ONLY', 'body' => 'FIRST BODY',
            'variants' => [['locale' => 'de', 'title' => 'DE title', 'body' => 'Bemerkungen'],
                ['locale' => 'en', 'title' => 'EN title', 'body' => '<script>message</script>']]];
        $html = $this->renderPreview('messages/announcement-preview', $data);
        foreach (['DE title', 'EN title', 'Bemerkungen', 'Speichern', 'lang="de"', 'lang="en"', '&lt;script&gt;name&lt;/script&gt;', '&lt;script&gt;message&lt;/script&gt;'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('FIRST ONLY', $html);
        unset($data['variants']);
        $fallback = $this->renderPreview('messages/announcement-preview', $data);
        $this->assertStringContainsString('FIRST ONLY', $fallback);
        $this->assertStringContainsString('FIRST BODY', $fallback);
    }

    public function test_runtime_message_data_is_rendered_in_both_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $html = $this->renderPreview('documents/viewer-content', ['url' => '/private/document', 'mediaType' => 'image/png'], $locale);
            $key = 'Dokument konnte nicht angezeigt werden. Bitte erneut öffnen oder herunterladen.';
            $this->assertStringContainsString('data-render-error-text="'.e($locale === 'en' ? $this->catalog()[$key] : $key).'"', $html);
        }
    }

    public function test_lookup_badges_translate_only_labels_and_keep_source_colors(): void
    {
        $tenant = (object) ['id' => 7];
        $row = (object) ['id' => 41, 'tenant_id' => 7, 'name' => 'Offen', 'code' => 'OFFEN'];
        $options = ['status' => ['OFFEN' => $row]];
        $statuses = ['OFFEN' => $row];
        $colors = ['offen' => '#123456', 'open' => '#abcdef'];
        foreach (['de' => 'Offen', 'en' => 'Open'] as $locale => $expected) {
            foreach (['planning/badge', 'bus/badge'] as $view) {
                $html = $this->renderPreview($view, compact('tenant', 'options', 'statuses', 'colors') + ['category' => 'status', 'code' => 'OFFEN'], $locale);
                $this->assertStringContainsString('>'.$expected.'</span>', $html);
                $this->assertStringContainsString('#123456', $html);
                $this->assertStringNotContainsString('#abcdef', $html);
                $this->assertSame('Offen', $row->name);
                $this->assertSame('OFFEN', $row->code);
            }
        }
        $html = $this->renderPreview('planning/badge', compact('tenant', 'colors') + ['options' => [], 'category' => 'status', 'code' => 'CUSTOM_CODE']);
        $this->assertStringContainsString('CUSTOM_CODE', $html);
        $row->name = '<script>custom label</script>';
        $html = $this->renderPreview('planning/badge', compact('tenant', 'options', 'colors') + ['category' => 'status', 'code' => 'OFFEN']);
        $this->assertStringContainsString('&lt;script&gt;custom label&lt;/script&gt;', $html);
        $row->name = 'Offen';
        $row->tenant_id = 8;
        $html = $this->renderPreview('planning/badge', compact('tenant', 'options', 'colors') + ['category' => 'status', 'code' => 'OFFEN']);
        $this->assertStringContainsString('>Offen</span>', $html, 'A foreign tenant row must not acquire this tenant\'s translation.');
    }

    public function test_localized_lookup_maps_are_displayed_verbatim(): void
    {
        foreach (['public/registration' => 3, 'portal/member-edit' => 1] as $view => $count) {
            $source = file_get_contents($this->root().'/resources/views/clubunio/'.$view.'.blade.php');
            preg_match_all('/<option value="\{\{ \$id \}\}".*?>(.*?)<\/option>/s', $source, $matches);
            $this->assertCount($count, $matches[1]);
            foreach ($matches[1] as $label) {
                foreach (['de', 'en'] as $locale) {
                    foreach (['Offen', '<b>Custom EN label</b>'] as $name) {
                        $this->assertSame(e($name), $this->renderPreview($view, compact('name'), $locale, $label));
                    }
                }
            }
        }
    }

    public function test_training_phase_options_keep_raw_values_in_both_rendering_branches(): void
    {
        $source = file_get_contents($this->root().'/resources/views/clubunio/training/show.blade.php');
        preg_match_all('/@foreach\(\[.*?\] as \$phase\).*?@endforeach/s', $source, $loops);
        $this->assertCount(2, $loops[0]);
        $phases = ['Einstieg', 'Hauptteil', 'Abschluss'];
        foreach ($loops[0] as $loop) {
            foreach (['de', 'en'] as $locale) {
                foreach ($phases as $selected) {
                    $html = $this->renderPreview('training/show', ['i' => 0, 'exercise' => ['phase' => $selected]], $locale, $loop);
                    $dom = new \DOMDocument;
                    $dom->loadHTML('<?xml encoding="UTF-8"><select>'.$html.'</select>');
                    $values = [];
                    foreach ($dom->getElementsByTagName('option') as $option) {
                        $value = $option->getAttribute('value');
                        $values[] = $value;
                        $this->assertSame($value === $selected, $option->hasAttribute('selected'));
                        $this->assertSame($locale === 'en' ? $this->catalog()[$value] : $value, $option->textContent);
                    }
                    $this->assertSame($phases, $values);
                }
            }
        }
    }

    public function test_transfer_blocked_count_uses_raw_state_in_every_locale(): void
    {
        $source = file_get_contents($this->root().'/resources/views/clubunio/season-transfer-preview.blade.php');
        preg_match('/<p class="text-sm">(.*?)<\/p>/s', $source, $match);
        $this->assertNotEmpty($match);
        $plan = ['rows' => [['state' => 'neu'], ['state' => 'blockiert'], ['state' => 'blockiert']]];
        foreach (['de', 'en'] as $locale) {
            $html = $this->renderPreview('season-transfer-preview', compact('plan'), $locale, $match[1]);
            $this->assertStringContainsString('2 '.e($locale === 'en' ? $this->catalog()['blockiert'] : 'blockiert'), $html);
        }
    }

    public function test_translation_calls_are_not_used_in_view_predicates_or_option_values(): void
    {
        $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
        $finder = new \PhpParser\NodeFinder;
        $isTranslation = fn ($node) => $node instanceof \PhpParser\Node\Expr\FuncCall
            && $node->name instanceof \PhpParser\Node\Name
            && in_array($node->name->toString(), ['__', 'trans', 'trans_choice'], true);
        foreach ($this->views() as $path => $source) {
            preg_match_all('/<option\b(.*?)>/s', $source, $options);
            foreach ($options[1] as $attributes) {
                $this->assertMatchesRegularExpression('/\bvalue=/', $attributes, $path);
                $this->assertDoesNotMatchRegularExpression('/\bvalue="[^"]*\b(?:__|trans)\s*\(/', $attributes, $path);
            }
            $nodes = $parser->parse($this->compiler()->compileString($source));
            foreach ($finder->findInstanceOf($nodes, \PhpParser\Node\Expr::class) as $node) {
                $operands = [];
                if ($node instanceof \PhpParser\Node\Expr\BinaryOp
                    && in_array($node->getOperatorSigil(), ['==', '===', '!=', '!==', '<', '>', '<=', '>=', '<=>'], true)) {
                    $operands = [$node->left, $node->right];
                } elseif (($node instanceof \PhpParser\Node\Expr\MethodCall || $node instanceof \PhpParser\Node\Expr\FuncCall)
                    && ($node->name instanceof \PhpParser\Node\Identifier || $node->name instanceof \PhpParser\Node\Name)
                    && in_array((string) $node->name, ['where', 'firstWhere', 'whereIn', 'whereNotIn', 'contains', 'in_array', 'array_search'], true)) {
                    $operands = $node->args;
                }
                $this->assertNull($finder->findFirst($operands, $isTranslation), $path.': translated predicate at compiled line '.$node->getStartLine());
            }
        }
    }

    public function test_lookup_choices_preserve_codes_and_editable_source_names(): void
    {
        $source = file_get_contents($this->root().'/resources/views/clubunio/planning/absences.blade.php');
        $this->assertStringContainsString('value="{{ $option->code }}"', $source);
        preg_match('/\{\{ app\(\\\\App\\\\ClubUnio\\\\Services\\\\LookupTranslations::class\)->label\(\x27planning_options\x27.*?\}\}/', $source, $match);
        $this->assertNotEmpty($match);
        $option = (object) ['id' => 9, 'tenant_id' => 7, 'name' => 'Krank', 'code' => 'KRANK'];
        $tenant = (object) ['id' => 7];
        foreach (['de' => 'Krank', 'en' => 'Ill'] as $locale => $expected) {
            $this->assertSame($expected, $this->renderPreview('planning/absences', compact('option', 'tenant'), $locale, $match[0]));
        }
        foreach (['planning/options' => '$option', 'bus/options' => '$item'] as $view => $variable) {
            $editor = file_get_contents($this->root().'/resources/views/clubunio/'.$view.'.blade.php');
            $this->assertStringContainsString('name="name" value="{{ '.$variable.'->name }}"', $editor);
        }
        $season = file_get_contents($this->root().'/resources/views/clubunio/season-planning.blade.php');
        $this->assertStringContainsString('{{ $season->name }}', $season);
        $this->assertStringContainsString('{{ $t->name }}', $season);
        $members = file_get_contents($this->root().'/resources/views/clubunio/portal/members.blade.php');
        $this->assertStringContainsString("trim(\$member->first_name.' '.\$member->last_name)", $members);
    }
}
