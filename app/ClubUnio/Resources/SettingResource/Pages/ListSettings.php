<?php

namespace App\ClubUnio\Resources\SettingResource\Pages;

use App\ClubUnio\Resources\SettingResource;
use App\ClubUnio\Services\BexioConnectionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSettings extends ListRecords
{
    protected function getHeaderActions(): array
    {
        return [Action::make('bexio')->label('Bexio-Verbindung')->icon('heroicon-o-link')
            ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->url(fn () => route('clubunio.bexio.show', Filament::getTenant()->slug))];
    }

    protected static string $resource = SettingResource::class;

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Alle')];
        foreach (['general' => 'Allgemein', 'app' => 'App', 'bexio' => 'Bexio', 'integrations' => 'Integrationen'] as $area => $label) {
            $tabs[$area] = Tab::make($label)->modifyQueryUsing(fn ($query) => $query->where('area', $area));
        }

        return $tabs;
    }
}
