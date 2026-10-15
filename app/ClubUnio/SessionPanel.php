<?php

namespace App\ClubUnio;

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

class SessionPanel extends Panel
{
    public function getNavigation(): array
    {
        $groups = parent::getNavigation();
        $icons = ['mitglied' => 'user-group', 'anmeldung' => 'user-plus', 'saison' => 'calendar-days',
            'mannschaft' => 'users', 'staff' => 'identification', 'trainer' => 'academic-cap',
            'training' => 'academic-cap', 'rechnung' => 'document-text', 'spesen' => 'receipt-percent',
            'beitrag' => 'banknotes', 'bank' => 'building-library', 'bus' => 'truck',
            'signage' => 'tv', 'wett' => 'trophy', 'spiel' => 'trophy', 'platz' => 'map-pin',
            'dokument' => 'paper-clip', 'benutzer' => 'user-circle', 'berechtigung' => 'shield-check',
            'vorlage' => 'envelope', 'einstellung' => 'cog-6-tooth', 'land' => 'globe-europe-africa'];
        foreach ($groups as $group) {
            if ($group->getIcon()) {
                continue;
            }
            foreach ($group->getItems() as $item) {
                if ($item->getIcon() && $item->getIcon() !== 'heroicon-o-adjustments-horizontal') {
                    continue;
                }
                $icon = 'squares-2x2';
                foreach ($icons as $word => $candidate) {
                    if (str_contains(mb_strtolower($item->getLabel()), $word)) {
                        $icon = $candidate;
                        break;
                    }
                }
                $item->icon('heroicon-o-'.$icon);
            }
        }

        return $groups;
    }

    public function getUrl(?Model $tenant = null): ?string
    {
        return url('/');
    }

    public function getRedirectUrl(?Model $tenant = null): ?string
    {
        return $this->auth()->check() ? url('/') : $this->getLoginUrl();
    }
}
