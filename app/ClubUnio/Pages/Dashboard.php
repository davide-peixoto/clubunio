<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Illuminate\Support\Str;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getWidgets(): array
    {
        return array_values(array_filter(parent::getWidgets(),
            fn ($widget) => $widget !== \App\ClubUnio\Widgets\PendingReservationRequests::class));
    }

    public function getHeading(): string
    {
        $firstName = Str::before(trim((string) Filament::auth()->user()?->display_name), ' ');

        return $firstName !== '' ? __('Willkommen, :name', ['name' => $firstName]) : __('Willkommen');
    }
}
