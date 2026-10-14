<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

class StatusAppearance
{
    public static function hex(?string $value): string
    {
        $tenant = Filament::getTenant();
        $actor = Filament::auth()->user();
        if ($tenant instanceof Tenant && $actor instanceof Account) {
            $colors = app(StatusStyleService::class)->colors($actor, $tenant);
            if (isset($colors[static::key($value)])) {
                return $colors[static::key($value)];
            }
        }

        return static::defaultHex($value);
    }

    public static function key(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value ?? '')));
    }

    public static function defaultHex(?string $value): string
    {
        $key = static::key($value);

        return match ($key) {
            '', 'ohne status' => '#64748b',
            'offen' => '#0369a1',
            'gesperrt' => '#b91c1c',
            'pendent' => '#a16207',
            'in prüfung', 'prüfung' => '#b45309',
            'freigegeben' => '#0f766e',
            'zur zahlung freigegeben' => '#4338ca',
            'bezahlt' => '#15803d',
            'abgelehnt' => '#b91c1c',
            'akzeptiert' => '#047857',
            'genehmigt' => '#15803d',
            'angefragt' => '#b45309',
            'storniert', 'unbekannt' => '#64748b',
            'probetraining' => '#7e22ce',
            'pool' => '#a21caf',
            'mitglied' => '#166534',
            'ausgetreten', 'ausgetretten' => '#57534e',
            default => '#'.substr(hash('sha256', $key), 0, 6),
        };
    }

    public static function palette(?string $value): array
    {
        return Color::hex(static::hex($value));
    }
}
