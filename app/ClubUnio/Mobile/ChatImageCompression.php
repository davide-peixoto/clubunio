<?php

namespace App\ClubUnio\Mobile;

/** Chat-only canonical image bytes. Never persists an original or changes PDFs. */
final class ChatImageCompression
{
    public const TARGET_BYTES = 358400;
    public const MAX_BYTES = 524288;
    public const MAX_INPUT_BYTES = 5242880;

    public function normalize(string $bytes, string $mime): array
    {
        abort_unless(strlen($bytes) > 0 && strlen($bytes) <= self::MAX_INPUT_BYTES
            && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $mime, 422);
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && $info[0] > 0 && $info[1] > 0 && $info[0] <= 10000 && $info[1] <= 10000
            && $info[0] * $info[1] <= 25000000, 422);
        abort_unless(extension_loaded('gd') && function_exists('imagecreatefromstring'), 503);
        $this->memoryBound($info[0] * $info[1], strlen($bytes));
        $orientation = $this->orientation($bytes, $mime);
        $source = @imagecreatefromstring($bytes);
        abort_unless($source instanceof \GdImage, 422);
        try {
            $source = $this->orient($source, $orientation);
            $width = imagesx($source); $height = imagesy($source);
            $alpha = null; $best = null;
            foreach ([1600, 1280, 960, 640] as $edge) {
                $ratio = min(1, $edge / max($width, $height));
                $w = max(1, (int) round($width * $ratio)); $h = max(1, (int) round($height * $ratio));
                if (isset($lastWidth) && $lastWidth === $w && $lastHeight === $h) continue;
                $lastWidth = $w; $lastHeight = $h;
                $canvas = imagecreatetruecolor($w, $h);
                abort_unless($canvas instanceof \GdImage, 422);
                try {
                    imagealphablending($canvas, false); imagesavealpha($canvas, true);
                    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
                    abort_unless(imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height), 422);
                    // Inspect only the bounded <=1600px output, not 25MP in PHP.
                    $alpha ??= $mime !== 'image/jpeg' && $this->transparent($canvas);
                    $webp = $alpha && function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
                    $outMime = $alpha ? ($webp ? 'image/webp' : 'image/png') : 'image/jpeg';
                    foreach ($outMime === 'image/png' ? [9] : [70, 58, 46] as $quality) {
                        $encoded = $this->encode($canvas, $outMime, $quality);
                        if ($best === null || strlen($encoded) < strlen($best['bytes'])) {
                            $best = ['bytes' => $encoded, 'mime' => $outMime,
                                'extension' => ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$outMime],
                                'width' => $w, 'height' => $h];
                        }
                        if (strlen($encoded) <= self::TARGET_BYTES) return $best;
                    }
                } finally { imagedestroy($canvas); }
            }
            abort_unless($best !== null && strlen($best['bytes']) <= self::MAX_BYTES, 422);
            return $best;
        } finally { imagedestroy($source); }
    }

    private function memoryBound(int $pixels, int $inputBytes): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') return;
        abort_unless(preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $limit, $parts), 503);
        $bytes = (int) $parts[1] * (1024 ** array_search(strtoupper($parts[2]), ['', 'K', 'M', 'G'], true));
        $estimate = $pixels * 12 + min($pixels, 1600 * 1600) * 12 + $inputBytes * 3 + 33554432;
        abort_unless($bytes - memory_get_usage(true) >= $estimate, 422);
    }

    private function transparent(\GdImage $image): bool
    {
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if ((imagecolorat($image, $x, $y) & 0x7f000000) !== 0) return true;
            }
        }
        return false;
    }

    private function encode(\GdImage $image, string $mime, int $quality): string
    {
        ob_start();
        try {
            $ok = match ($mime) {
                'image/webp' => imagewebp($image, null, $quality),
                'image/png' => imagepng($image, null, $quality),
                default => imagejpeg($image, null, $quality),
            };
            $bytes = ob_get_contents();
        } finally { ob_end_clean(); }
        abort_unless($ok && is_string($bytes) && $bytes !== ''
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $mime, 422);
        return $bytes;
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle === 0) return $image;
        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
        abort_unless($rotated instanceof \GdImage, 422);
        imagealphablending($rotated, false); imagesavealpha($rotated, true);
        imagedestroy($image);
        return $rotated;
    }

    /** Bounded container/TIFF reader; EXIF never reaches the encoded output. */
    private function orientation(string $bytes, string $mime): int
    {
        $length = strlen($bytes);
        if ($mime === 'image/jpeg') {
            for ($p = 2, $segments = 0; $p + 4 <= $length && $segments < 512; $segments++) {
                if (ord($bytes[$p]) !== 255) break;
                while ($p < $length && ord($bytes[$p]) === 255) $p++;
                if ($p >= $length) break;
                $marker = ord($bytes[$p++]);
                if (in_array($marker, [0xda, 0xd9], true)) break;
                if ($marker === 1 || ($marker >= 0xd0 && $marker <= 0xd8)) continue;
                if ($p + 2 > $length) break;
                $size = unpack('n', substr($bytes, $p, 2))[1];
                if ($size < 2 || $p + $size > $length) break;
                if ($marker === 0xe1 && $size >= 8 && substr($bytes, $p + 2, 6) === "Exif\0\0") {
                    return $this->tiff(substr($bytes, $p + 8, $size - 8));
                }
                $p += $size;
            }
        } elseif ($mime === 'image/png') {
            for ($p = 8, $chunks = 0; $p + 12 <= $length && $chunks < 4096; $chunks++) {
                $size = unpack('N', substr($bytes, $p, 4))[1];
                if ($size > $length - $p - 12) break;
                if (substr($bytes, $p + 4, 4) === 'eXIf') return $this->tiff(substr($bytes, $p + 8, $size));
                $p += 12 + $size;
            }
        } else {
            for ($p = 12, $chunks = 0; $p + 8 <= $length && $chunks < 4096; $chunks++) {
                $size = unpack('V', substr($bytes, $p + 4, 4))[1];
                if ($size > $length - $p - 8) break;
                if (substr($bytes, $p, 4) === 'EXIF') {
                    $exif = substr($bytes, $p + 8, $size);
                    return $this->tiff(str_starts_with($exif, "Exif\0\0") ? substr($exif, 6) : $exif);
                }
                $p += 8 + $size + ($size % 2);
            }
        }
        return 1;
    }

    private function tiff(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 8 || !in_array(substr($bytes, 0, 2), ['II', 'MM'], true)) return 1;
        $little = substr($bytes, 0, 2) === 'II';
        $short = fn (int $p) => unpack($little ? 'v' : 'n', substr($bytes, $p, 2))[1];
        $long = fn (int $p) => unpack($little ? 'V' : 'N', substr($bytes, $p, 4))[1];
        if ($short(2) !== 42) return 1;
        $offset = $long(4);
        if ($offset < 8 || $offset > $length - 2) return 1;
        $count = $short($offset);
        if ($count > 512 || $count * 12 > $length - $offset - 2) return 1;
        for ($p = $offset + 2, $i = 0; $i < $count; $i++, $p += 12) {
            if ($short($p) === 0x112 && $short($p + 2) === 3 && $long($p + 4) === 1) {
                $value = $short($p + 8);
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }
}
