<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Illuminate\Support\Str;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function glassAvailable(): bool
    {
        return (bool) config('clubunio-dashboard.enabled', false)
            && is_file(public_path('js/clubunio-dashboard.js'))
            && is_file(public_path('css/clubunio-dashboard.css'));
    }

    public function glassSelected(): bool
    {
        return $this->glassAvailable()
            && session($this->designSessionKey(), 'glass') === 'glass';
    }

    protected function designSessionKey(): string
    {
        return 'clubunio.dashboard.design.'.Filament::auth()->id();
    }

    public function selectDesign(string $design): void
    {
        abort_unless($this->glassAvailable() && in_array($design, ['classic', 'glass'], true), 403);
        session()->put($this->designSessionKey(), $design);
        $this->redirect(\App\ClubUnio\Pages\Dashboard::getUrl(), navigate: false);
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
