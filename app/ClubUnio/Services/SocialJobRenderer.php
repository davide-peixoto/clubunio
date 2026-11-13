<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SocialJobRenderer
{
    public function __construct(private SocialStudioData $data) {}

    public function render(int $tenant, array $design, array $fixtures, string $club, string $date): string
    {
        $height = SocialStudioData::FORMATS[$design['format']];
        $image = imagecreatetruecolor(1080, $height);
        imagefill($image, 0, 0, $this->color($image, $design['background']));
        $media = $this->data->table($tenant, 'media')->whereIn('id', array_column($design['layers'], 'media'))->pluck('path', 'id');
        $first = $fixtures[0];
        $fields = $first + ['club' => $club, 'date' => implode('.', array_reverse(explode('-', $date))),
            'fixtures' => implode("\n\n", array_map(fn ($row) => implode('  ·  ', array_filter([
                $row['time'], $row['home'].' – '.$row['away'], $row['place'],
            ])), $fixtures))];
        $fields['score'] = isset($first['score']) ? implode(' : ', $first['score']) : '';
        foreach ($design['layers'] as $layer) {
            $w = (int) round($layer['w']);
            $h = (int) round($layer['h']);
            $part = imagecreatetruecolor($w, $h);
            imagealphablending($part, false);
            imagesavealpha($part, true);
            $clear = imagecolorallocatealpha($part, 0, 0, 0, 127);
            imagefill($part, 0, 0, $clear);
            imagealphablending($part, true);
            $ink = $this->color($part, $layer['fill'] ?? '#ffffff');
            if ($layer['type'] === 'rect') {
                imagefilledrectangle($part, 0, 0, $w - 1, $h - 1, $ink);
            } elseif ($layer['type'] === 'image') {
                $path = $media->get($layer['media']);
                $bytes = $path ? Storage::disk('local')->get($path) : null;
                $source = $bytes ? @imagecreatefromstring($bytes) : false;
                if (! $source) {
                    throw new \RuntimeException('Missing automation image');
                }
                $iw = imagesx($source);
                $ih = imagesy($source);
                $cw = min($iw, $ih * $w / $h);
                $ch = min($ih, $iw * $h / $w);
                $crop = $layer['crop'] ?? ['x' => ($iw - $cw) / (2 * $iw), 'y' => ($ih - $ch) / (2 * $ih), 'w' => $cw / $iw, 'h' => $ch / $ih];
                imagealphablending($part, false);
                imagecopyresampled($part, $source, 0, 0, (int) ($iw * $crop['x']), (int) ($ih * $crop['y']),
                    $w, $h, max(1, (int) ($iw * $crop['w'])), max(1, (int) ($ih * $crop['h'])));
                if ($layer['flipX'] ?? false) {
                    imageflip($part, IMG_FLIP_HORIZONTAL);
                }
            } else {
                $value = $layer['type'] === 'field' ? ($fields[$layer['field']] ?? '') : ($layer['text'] ?? '');
                $this->text($part, $layer, $w, $h, (string) $value, $ink);
            }
            $angle = (float) $layer['angle'];
            $radians = deg2rad($angle);
            $x = $layer['x'] + min(0, $w * cos($radians)) + min(0, -$h * sin($radians));
            $y = $layer['y'] + min(0, $w * sin($radians)) + min(0, $h * cos($radians));
            if ($angle !== 0.0) {
                $part = imagerotate($part, -$angle, $clear);
            }
            imagecopy($image, $part, (int) $x, (int) $y, 0, 0, imagesx($part), imagesy($part));
        }
        $path = 'social-studio/'.$tenant.'/'.Str::uuid().'.jpg';
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagejpeg($image, $stream, 91)) {
                throw new \RuntimeException('Automation image encoding failed');
            }
            rewind($stream);
            if (! Storage::disk('local')->put($path, $stream)) {
                throw new \RuntimeException('Automation image storage failed');
            }
        } finally {
            fclose($stream);
        }

        return $path;
    }

    private function text(\GdImage $image, array $layer, int $w, int $h, string $value, int $ink): void
    {
        $font = public_path('vendor/pdfjs/standard_fonts/LiberationSans-'.(($layer['bold'] ?? false) ? 'Bold' : 'Regular').'.ttf');
        if (! is_readable($font) || ! function_exists('imagettfbbox')) {
            throw new \RuntimeException('Automation font unavailable');
        }
        $size = (int) ($layer['size'] ?? 50);
        do {
            $pt = $size * .75;
            $measure = fn (string $line) => (imagettfbbox($pt, 0, $font, $line)[2] ?? 0);
            $lines = [];
            foreach (explode("\n", $value) as $paragraph) {
                $line = '';
                foreach (preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $next = $line === '' ? $word : $line.' '.$word;
                    if ($line !== '' && $measure($next) > $w) {
                        $lines[] = $line;
                        $next = $word;
                    }
                    $line = $next;
                }
                $lines[] = $line;
            }
            $fits = count($lines) * $size * 1.17 <= $h && max(array_map($measure, $lines)) <= $w;
        } while (! $fits && --$size >= 12);
        if (! $fits && trim($value) !== '') {
            throw new \RuntimeException('Automation layout overflow');
        }
        foreach ($lines as $i => $line) {
            $width = $measure($line);
            $x = match ($layer['align'] ?? 'left') { 'center' => ($w - $width) / 2, 'right' => $w - $width, default => 0 };
            imagettftext($image, $pt, 0, (int) $x, (int) ($size * (.88 + 1.17 * $i)), $ink, $font, $line);
        }
    }

    private function color(\GdImage $image, string $hex): int
    {
        return imagecolorallocate($image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    }
}
