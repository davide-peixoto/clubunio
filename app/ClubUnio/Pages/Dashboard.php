<?php

namespace App\ClubUnio\Pages;

use Filament\Facades\Filament;
use Illuminate\Support\Str;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getHeading(): string
    {
        $firstName = Str::before(trim((string) Filament::auth()->user()?->display_name), ' ');

        return $firstName !== '' ? 'Willkommen, '.$firstName : 'Willkommen';
    }
}
