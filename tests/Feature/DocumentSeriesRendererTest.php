<?php

namespace Tests\Feature;

use App\ClubUnio\Services\DocumentSeriesDefaults;
use App\ClubUnio\Services\DocumentSeriesRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentSeriesRendererTest extends TestCase
{
    private function definition(): array
    {
        $d = DocumentSeriesDefaults::all()['legacy-player'];
        unset($d['type_code']);

        return $d + ['type_id' => 1];
    }

    public function test_four_cleaned_defaults_validate_and_preserve_legacy_prices(): void
    {
        $renderer = app(DocumentSeriesRenderer::class);
        $this->assertCount(4, DocumentSeriesDefaults::all());
        foreach (DocumentSeriesDefaults::all() as $default) {
            unset($default['type_code']);
            $d = $renderer->definition($default + ['type_id' => 1]);
            $this->assertTrue($d['signature_required']);
            $this->assertNotEmpty($d['text']);
        }
        $this->assertSame([3550, 1260, 4600, 3500, 6000, 3900], array_column($this->definition()['items'], 'price_cents'));
    }

    public function test_plain_text_rendering_escapes_markup_and_calculates_integer_prices(): void
    {
        $renderer = app(DocumentSeriesRenderer::class);
        $d = $renderer->definition($this->definition());
        $d['text'] = '<script>alert(1)</script> {{Mitglied.Vorname}}';
        $d['items'] = [['description' => '<img src="https://invalid">', 'quantity' => 3, 'price_cents' => 1260]];
        $values = array_fill_keys(DocumentSeriesRenderer::FIELDS, 'Fixture');
        $values['Mitglied.Vorname'] = '{{not template code}}';
        $snapshot = ['definition' => $renderer->personalize($d, $values), 'values' => $values, 'club' => 'Synthetic club', 'member_name' => 'Synthetic member'];
        $html = $renderer->html($snapshot);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('{{not template code}}', $html);
        $this->assertStringContainsString('37.80', $html);
        $this->assertStringStartsWith('%PDF-', $renderer->pdf($snapshot));
    }

    public function test_unknown_placeholders_rejected_in_positions_as_well_as_text(): void
    {
        $d = $this->definition();
        $d['items'][0]['size'] = '{{Unknown.field}}';
        $this->expectException(ValidationException::class);
        app(DocumentSeriesRenderer::class)->definition($d);
    }

    public function test_missing_and_malformed_placeholders_rejected(): void
    {
        foreach (['{{Schluessel.Nummer}}', '{{broken', 'broken}}'] as $text) {
            try {
                app(DocumentSeriesRenderer::class)->substitute($text, []);
                $this->fail('Missing/malformed placeholder accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_definition_limits_and_hash_are_deterministic(): void
    {
        $this->assertSame(DocumentSeriesRenderer::hash(['z' => ['b' => 1, 'a' => 2], 'a' => 3]), DocumentSeriesRenderer::hash(['a' => 3, 'z' => ['a' => 2, 'b' => 1]]));
        $d = $this->definition();
        $d['items'][0]['price_cents'] = -1;
        $this->expectException(ValidationException::class);
        app(DocumentSeriesRenderer::class)->definition($d);
    }

    public function test_page_blade_compiles_without_database_or_route_registration(): void
    {
        $blade = app('blade.compiler')->compileString(file_get_contents(resource_path('views/clubunio/document-series/page.blade.php')));
        $this->assertStringNotContainsString('@else)', $blade);
        $this->assertStringContainsString('Geprüfte PDF-Rückgabe', $blade);
        $this->assertNotEmpty(token_get_all($blade, TOKEN_PARSE));
    }

    public function test_all_defaults_render_synthetic_a4_documents(): void
    {
        $renderer = app(DocumentSeriesRenderer::class);
        foreach (DocumentSeriesDefaults::all() as $key => $d) {
            unset($d['type_code']);
            $d = $renderer->definition($d + ['type_id' => 1]);
            $values = array_fill_keys(DocumentSeriesRenderer::FIELDS, 'Synthetic test');
            $values['Dokument.Datum'] = '25.10.2030';
            $snapshot = ['definition' => $renderer->personalize($d, $values), 'values' => $values, 'club' => 'Synthetic QA club', 'member_name' => 'Synthetic QA member'];
            $bytes = $renderer->pdf($snapshot);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertGreaterThan(10000, strlen($bytes));
            if (getenv('CLUBUNIO_SERIES_PDF_QA') === '1') {
                $folder = storage_path('framework/testing/document-series-qa');
                File::ensureDirectoryExists($folder);
                file_put_contents($folder.'/'.$key.'.pdf', $bytes);
            }
        }
    }
}
