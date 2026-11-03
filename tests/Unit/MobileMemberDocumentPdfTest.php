<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\MobileMemberDocumentPdf;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class MobileMemberDocumentPdfTest extends TestCase
{
    private function original(int $pages = 2): string
    {
        $pdf = new \FPDF;
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->AddPage($page % 2 === 0 ? 'L' : 'P', 'A4'); $pdf->SetFont('Helvetica', '', 15);
            $pdf->Cell(0, 10, 'Synthetic original page '.$page, 0, 1); $pdf->SetFont('Helvetica', '', 11);
            $pdf->MultiCell(0, 7, 'Unchanged test document. Material: 2 shirts, 1 training bag. No real member data.');
        }
        return $pdf->Output('S');
    }

    private function metadata(string $original): array
    {
        return ['club' => 'ClubUnio - Synthetischer Testverein', 'title' => 'Materialuebergabe Saison2026/2027',
            'member' => 'Synthetisches Mitglied', 'signer' => 'Synthetische Elternvertretung', 'username' => 'synthetic@example.invalid',
            'signed_at' => '2026-11-03 21:00:00', 'receipt' => '42', 'kind' => 'parent', 'original_sha256' => hash('sha256', $original)];
    }

    public function test_signed_receipt_preserves_original_and_page_geometry_and_adds_real_stroke_evidence(): void
    {
        $original = $this->original(); $before = hash('sha256', $original); $service = new MobileMemberDocumentPdf;
        $normalized = $service->normalize($original);
        $this->assertSame($original, $normalized['bytes']); $this->assertSame(2, $normalized['pages']);
        $signed = $service->sign($original, [[[.08, .65], [.20, .22], [.27, .78], [.42, .35], [.6, .55], [.9, .40]]], $this->metadata($original));
        $this->assertSame($before, hash('sha256', $original)); $this->assertSame(3, $signed['pages']);
        $this->assertStringStartsWith('%PDF-', $signed['bytes']); $this->assertLessThan(MobileMemberDocumentPdf::MAX_SIGNED, strlen($signed['bytes']));
        $pdf = new Fpdi; $this->assertSame(3, $pdf->setSourceFile(StreamReader::createByString($signed['bytes'])));
        $first = $pdf->getTemplateSize($pdf->importPage(1)); $second = $pdf->getTemplateSize($pdf->importPage(2));
        $this->assertSame('P', $first['orientation']); $this->assertSame('L', $second['orientation']);
        if (getenv('CLUBUNIO_PDF_QA') === '1') {
            file_put_contents(dirname(__DIR__, 2).'/storage/app/mobile-document-qa-original.pdf', $original);
            file_put_contents(dirname(__DIR__, 2).'/storage/app/mobile-document-qa-signed.pdf', $signed['bytes']);
        }
    }

    public function test_stroke_bounds_are_enforced_before_rendering(): void
    {
        $service = new MobileMemberDocumentPdf;
        foreach ([[], [[[.1, .1]]], [[[.1, .1], [.11, .1]]], [[[0, 0], [1.1, .8]]], [[['.1', '.2'], [.8, .8]]], [[[0, 0], [INF, .7]]], [[[0, 0], [NAN, .7]]], [2 => [[.1, .1], [.8, .8]]], array_fill(0, 101, [[0, 0], [1, 1]]), [array_fill(0, 4001, [0, 0])]] as $input) {
            try { $service->strokes($input); $this->fail('Invalid strokes accepted'); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame([[[0., 0.], [1., 1.]]], $service->strokes([[[0, 0], [1, 1]]]));
    }

    public function test_changed_hash_malformed_pdf_and_excessive_pages_fail_closed(): void
    {
        $service = new MobileMemberDocumentPdf; $original = $this->original();
        try { $service->sign($original.' ', [[[0., 0.], [1., 1.]]], $this->metadata($original)); $this->fail('Changed original accepted'); }
        catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        foreach (['%PDF-invalid %%EOF', str_repeat('x', MobileMemberDocumentPdf::MAX_ORIGINAL + 1), $this->original(51)] as $bytes) {
            try { $service->normalize($bytes); $this->fail('Invalid PDF accepted'); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $pdf = new \FPDF; $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 12); $pdf->Cell(50, 10, 'Annotation', 0, 0, '', false, 'https://example.invalid');
        $this->expectException(InvalidArgumentException::class); $service->normalize($pdf->Output('S'));
    }

    public function test_image_conversion_is_stable_and_returns_a_real_single_page_pdf(): void
    {
        $image = imagecreatetruecolor(32, 24); imagefill($image, 0, 0, imagecolorallocate($image, 22, 75, 138));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $service = new MobileMemberDocumentPdf; $first = $service->normalize($bytes); $second = $service->normalize($bytes);
        $this->assertSame(1, $first['pages']); $this->assertSame($first['bytes'], $second['bytes']);
        $this->assertStringStartsWith('%PDF-', $first['bytes']);
    }
}
