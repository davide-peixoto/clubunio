<?php

namespace App\ClubUnio\Mobile;

use InvalidArgumentException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;

/** Pure PDF/stroke handling ported from the existing SVS receipt workflow. */
final class MobileMemberDocumentPdf
{
    public const MAX_ORIGINAL = 10_485_760;
    public const MAX_SIGNED = 20_971_520;

    private function import(string $bytes): array
    {
        if (strlen($bytes) < 8 || strlen($bytes) > self::MAX_ORIGINAL || ! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -2048), '%%EOF')) {
            throw new InvalidArgumentException('Bitte eine PDF bis10 MB verwenden.');
        }
        try {
            $parser = new PdfParser(StreamReader::createByString($bytes));
            $catalog = $parser->getCatalog()->value;
            foreach (['AcroForm', 'OpenAction', 'AA', 'Names'] as $key) {
                if (isset($catalog[$key])) { throw new \RuntimeException('Interactive PDF unsupported'); }
            }
            $reader = new PdfReader($parser);
            $count = $reader->getPageCount();
            if ($count < 1 || $count > 50) { throw new \RuntimeException('Page limit'); }
            for ($page = 1; $page <= $count; $page++) {
                $dictionary = $reader->getPage($page)->getPageDictionary()->value;
                if (isset($dictionary['Annots']) || isset($dictionary['AA'])) { throw new \RuntimeException('Annotations unsupported'); }
            }
            $pdf = new Fpdi;
            $pages = $pdf->setSourceFile(StreamReader::createByString($bytes));
            if ($pages !== $count) { throw new \RuntimeException('Page count mismatch'); }
            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
            return [$pdf, $pages];
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('Bitte eine flache, ungeschuetzte PDF1.4 ohne Formularfelder/Anmerkungen mit maximal50 Seiten verwenden.', 0, $error);
        }
    }

    public function normalize(string $bytes): array
    {
        if (strlen($bytes) < 1 || strlen($bytes) > self::MAX_ORIGINAL) { throw new InvalidArgumentException('Datei darf maximal10 MB gross sein.'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mime === 'application/pdf') {
            [, $pages] = $this->import($bytes);
            return ['bytes' => $bytes, 'pages' => $pages]; // Keep the exact original PDF bytes/hash.
        }
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) { throw new InvalidArgumentException('Bitte PDF, JPG oder PNG auswaehlen.'); }
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > 10_000 || $size[1] > 10_000 || $size[0] * $size[1] > 20_000_000) {
            throw new InvalidArgumentException('Bild ungueltig oder groesser als20 Megapixel.');
        }
        $path = tempnam(sys_get_temp_dir(), 'clubunio-document-');
        if ($path === false) { throw new \RuntimeException('Private temporary file unavailable'); }
        chmod($path, 0600);
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new \RuntimeException('Incomplete temporary file'); }
            $pdf = new class extends Fpdi {
                protected function _putinfo() { $this->CreationDate = 0; parent::_putinfo(); }
            };
            $pdf->AddPage($size[0] > $size[1] ? 'L' : 'P');
            $scale = min(($pdf->GetPageWidth() - 20) / $size[0], ($pdf->GetPageHeight() - 20) / $size[1]);
            $width = $size[0] * $scale; $height = $size[1] * $scale;
            $pdf->Image($path, ($pdf->GetPageWidth() - $width) / 2, ($pdf->GetPageHeight() - $height) / 2, $width, $height, $mime === 'image/jpeg' ? 'JPG' : 'PNG');
            $result = $pdf->Output('S');
            [, $pages] = $this->import($result);
            return ['bytes' => $result, 'pages' => $pages];
        } finally { @unlink($path); }
    }

    public function strokes(mixed $strokes): array
    {
        if (! is_array($strokes) || ! array_is_list($strokes) || count($strokes) < 1 || count($strokes) > 100) { throw new InvalidArgumentException('Bitte im Unterschriftsfeld unterschreiben.'); }
        $count = 0; $distance = 0.; $result = [];
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! array_is_list($stroke) || count($stroke) < 2) { throw new InvalidArgumentException('Unterschrift unvollstaendig.'); }
            $previous = null; $line = [];
            foreach ($stroke as $point) {
                if (! is_array($point) || ! array_is_list($point) || count($point) !== 2) { throw new InvalidArgumentException('Unterschrift ungueltig.'); }
                foreach ($point as $value) {
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1) { throw new InvalidArgumentException('Unterschrift ausserhalb des Feldes.'); }
                }
                if (++$count > 4000) { throw new InvalidArgumentException('Unterschrift zu umfangreich. Bitte erneut zeichnen.'); }
                $point = [round((float) $point[0], 6), round((float) $point[1], 6)];
                if ($previous !== null) { $distance += hypot($point[0] - $previous[0], $point[1] - $previous[1]); }
                $previous = $point; $line[] = $point;
            }
            $result[] = $line;
        }
        if ($distance < .05) { throw new InvalidArgumentException('Bitte eine vollstaendige Unterschrift zeichnen.'); }
        return $result;
    }

    public function sign(string $original, array $strokes, array $metadata): array
    {
        $strokes = $this->strokes($strokes);
        if (! isset($metadata['original_sha256']) || ! hash_equals($metadata['original_sha256'], hash('sha256', $original))) { throw new InvalidArgumentException('Original wurde inzwischen geaendert.'); }
        [$pdf, $pages] = $this->import($original);
        $pdf->AddPage('P', 'A4');
        $pdf->SetMargins(20, 20, 20); $pdf->SetAutoPageBreak(false);
        $pdf->SetXY(20, 18); $pdf->SetTextColor(22, 75, 138); $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->MultiCell(170, 6, $this->text(mb_substr($metadata['club'], 0, 160)));
        $pdf->SetFont('Helvetica', 'B', 19); $pdf->Cell(170, 14, 'Empfangsquittung', 0, 1);
        $pdf->SetTextColor(25, 32, 42); $pdf->SetFont('Helvetica', '', 10);
        foreach (['title' => 'Dokument', 'member' => 'Mitglied', 'signer' => 'Unterzeichnet von', 'username' => 'Persoenlicher Zugang', 'signed_at' => 'Zeitpunkt (UTC)', 'receipt' => 'Quittung'] as $key => $label) {
            $pdf->MultiCell(170, 6, $this->text($label.': '.mb_substr((string) $metadata[$key], 0, $key === 'username' ? 255 : ($key === 'title' ? 200 : 160))));
        }
        $pdf->Ln(4); $pdf->MultiCell(170, 6, $this->text('Ich habe das Dokument gelesen und bestaetige die darin beschriebene Uebernahme mit meiner Unterschrift.'));
        $pdf->MultiCell(170, 6, $this->text($metadata['kind'] === 'parent' ? 'Unterzeichnung als wirksame Elternvertretung fuer das genannte Mitglied.' : 'Unterzeichnung fuer die eigene Mitgliedschaft.'));
        $y = $pdf->GetY() + 6;
        if ($y > 185) { throw new InvalidArgumentException('Signaturmetadaten sind zu umfangreich.'); }
        $pdf->SetDrawColor(160, 177, 197); $pdf->Rect(20, $y, 170, 46);
        $pdf->SetDrawColor(20, 35, 55); $pdf->SetLineWidth(.45);
        foreach ($strokes as $stroke) {
            for ($i = 1; $i < count($stroke); $i++) {
                $pdf->Line(22 + $stroke[$i - 1][0] * 166, $y + 2 + $stroke[$i - 1][1] * 42,
                    22 + $stroke[$i][0] * 166, $y + 2 + $stroke[$i][1] * 42);
            }
        }
        $pdf->SetXY(20, $y + 52); $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(170, 5, 'SHA-256 des unveraenderten Originals:'); $pdf->MultiCell(170, 5, $metadata['original_sha256']);
        $pdf->MultiCell(170, 5, $this->text('Diese Bestaetigung bezieht sich auf die vorangehenden Seiten. Das Original wird separat aufbewahrt.'));
        $pdf->SetCreator('ClubUnio');
        $bytes = $pdf->Output('S');
        if (strlen($bytes) > self::MAX_SIGNED || $pdf->PageNo() !== $pages + 1) { throw new InvalidArgumentException('Signiertes PDF konnte nicht bestaetigt werden.'); }
        return ['bytes' => $bytes, 'pages' => $pages + 1];
    }

    private function text(string $value): string {
        $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value) ?? '';
        return iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value;
    }
}
