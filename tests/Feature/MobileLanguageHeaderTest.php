<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class MobileLanguageHeaderTest extends TestCase
{
    public function test_switcher_preserves_enabled_options_accessible_labels_and_immediate_post(): void
    {
        foreach (config('clubunio-languages.enabled') as $locale => $name) {
            app()->setLocale($locale);
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $dom->loadHTML('<?xml encoding="UTF-8">'.view('clubunio.language-switcher')->render());
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new DOMXPath($dom);
            $form = $xpath->query('//form')->item(0);
            $select = $xpath->query('//select')->item(0);
            $label = $xpath->query('//label')->item(0);

            $this->assertSame('cu-language-switcher', $form->getAttribute('class'));
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertSame(route('clubunio.language'), $form->getAttribute('action'));
            $this->assertSame(1, $xpath->query('//input[@name="_token"]')->length);
            $this->assertSame('locale', $select->getAttribute('name'));
            $this->assertSame('this.form.requestSubmit()', $select->getAttribute('onchange'));
            $this->assertSame(__('Sprache'), $select->getAttribute('aria-label'));
            $this->assertSame(__('Sprache'), $label->textContent);
            $this->assertSame($select->getAttribute('id'), $label->getAttribute('for'));
            $this->assertSame('cu-language-switcher-label', $label->getAttribute('class'));
            $this->assertSame($locale, $xpath->query('//option[@selected]')->item(0)->getAttribute('value'));
            $options = [];
            foreach ($xpath->query('//option') as $option) {
                $options[$option->getAttribute('value')] = $option->textContent;
            }
            $this->assertSame(config('clubunio-languages.enabled'), $options);
            $this->assertSame(0, $xpath->query('//button')->length);
            $this->assertSame(1, $xpath->query('//span[@class="cu-language-switcher-control"]/select')->length);
            $this->assertSame(2, $xpath->query('//*[local-name()="svg" and @aria-hidden="true"]')->length);
            $this->assertSame(1, $xpath->query('//link[contains(@href, "/css/clubunio-language-switcher.css?v=2")]')->length);
            $this->assertSame(strtoupper($locale), $xpath->query('//span[@class="cu-language-switcher-compact" and @aria-hidden="true"]')->item(0)->textContent);
        }
    }

    public function test_login_keeps_shared_switcher_and_shell_styles_in_both_languages(): void
    {
        foreach (array_keys(config('clubunio-languages.enabled')) as $locale) {
            $this->withSession(['clubunio.locale' => $locale])->get('/login')
                ->assertOk()->assertHeader('Content-Language', $locale)
                ->assertSee('class="cu-language-switcher"', false)
                ->assertSee('/css/svs.css?v=language-header-v4', false)
                ->assertSee('onchange="this.form.requestSubmit()"', false);
        }
    }
}
