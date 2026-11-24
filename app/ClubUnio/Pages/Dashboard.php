<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Illuminate\Support\Str;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getHeader(): ?\Illuminate\Contracts\View\View
    {
        return $this->glassSelected() ? view('clubunio.pages.dashboard-header') : parent::getHeader();
    }

    public function glassAvailable(): bool
    {
        return app(\App\ClubUnio\Services\ReactUi::class)->enabled()
            && is_file(public_path('js/clubunio-dashboard.js'))
            && is_file(public_path('css/clubunio-dashboard.css'));
    }

    public function glassSelected(): bool
    {
        return $this->glassAvailable();
    }

    public function getView(): string
    {
        return $this->glassAvailable() ? 'clubunio.pages.dashboard' : parent::getView();
    }

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
