<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

class BackendTheme
{
    public function brand(?Tenant $tenant = null): ?array
    {
        $tenant ??= Filament::getTenant();
        if (! $tenant || ! app(ReactUi::class)->enabled((int) $tenant->id)) { return null; }
        $key = self::class.'.'.$tenant->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, $this->designFor($tenant) + ['name' => $tenant->name]);
        }

        return request()->attributes->get($key);
    }

    protected function designFor(Tenant $tenant): array
    {
        return app(SignageBrandService::class)->design($tenant);
    }

    public function logo(bool $dark = false): string
    {
        $logo = $this->brand()['logo'] ?? '/brand/blue-v1/clubunio-logo-horizontal'.($dark ? '-white' : '').'.svg';

        return str_starts_with($logo, '/') ? asset($logo) : $logo;
    }

    public function tokens(string $color): array
    {
        $color = preg_match('/^#[a-f0-9]{6}$/iD', $color) ? strtolower($color) : '#164b8a';
        $ink = self::contrast($color, '#ffffff') >= self::contrast($color, '#000000') ? '#ffffff' : '#000000';
        $nav = $color;
        while (self::contrast($nav, '#ffffff') < 6) { $nav = self::mix($nav, '#000000', .08); }
        $text = $color;
        while (self::contrast($text, '#ffffff') < 4.5) { $text = self::mix($text, '#000000', .08); }
        $darkSoft = self::mix($color, '#152236', .86);
        $light = $color;
        while (min(self::contrast($light, '#152236'), self::contrast($light, $darkSoft)) < 4.5) { $light = self::mix($light, '#ffffff', .08); }
        $marker = self::mix($color, '#ffffff', .94);
        $symbol = $color;
        while (self::contrast($symbol, $marker) < 4.5) { $symbol = self::mix($symbol, '#000000', .08); }
        $tokens = [
            '--cu-brand-color' => $color, '--cu-brand-rgb' => implode(' ', self::rgb($color)),
            '--cu-brand-ink' => $ink, '--cu-brand-text' => $text, '--cu-brand-light' => $light,
            '--cu-brand-accent' => self::mix($color, '#ffffff', .35),
            '--cu-brand-soft' => self::mix($color, '#ffffff', .94),
            '--cu-brand-dark-soft' => $darkSoft,
            '--cu-brand-edge' => self::mix($color, '#ffffff', .80),
            '--cu-brand-hero' => self::mix($color, $ink === '#ffffff' ? '#000000' : '#ffffff', .16),
            '--cu-nav-ink' => '#e3ebf7', '--cu-nav-marker' => $marker, '--cu-nav-symbol' => $symbol,
            '--cu-nav-top' => $nav, '--cu-nav-bottom' => self::mix($nav, '#000000', .35),
        ];
        foreach (Color::hex($color) as $shade => $value) { $tokens['--primary-'.$shade] = $value; }
        $tokens['--primary-600'] = $color;

        return $tokens;
    }

    public static function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $rgb = array_map(fn ($v) => ($v /= 255) <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4, self::rgb($hex));

            return $rgb[0] * .2126 + $rgb[1] * .7152 + $rgb[2] * .0722;
        };
        $x = $luminance($a); $y = $luminance($b);

        return (max($x, $y) + .05) / (min($x, $y) + .05);
    }

    private static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(substr($hex, 1), 2));
    }

    private static function mix(string $a, string $b, float $weight): string
    {
        $x = self::rgb($a); $y = self::rgb($b);

        return '#'.implode('', array_map(fn ($i) => sprintf('%02x', (int) round($x[$i] * (1 - $weight) + $y[$i] * $weight)), [0, 1, 2]));
    }
}
