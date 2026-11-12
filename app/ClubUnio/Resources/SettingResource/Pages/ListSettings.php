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
        return [Action::make('defaultLanguage')->label('Vereinssprache')->icon('heroicon-o-language')
            ->visible(fn () => app(\App\ClubUnio\Services\AccountAdministrationService::class)->allowed(Filament::auth()->user(),Filament::getTenant()))
            ->schema([\Filament\Forms\Components\Select::make('locale')->label('Standardsprache')->required()
                ->options(fn () => app(\App\ClubUnio\Services\LanguagePreferences::class)->enabled()),
                \Filament\Forms\Components\Hidden::make('expected'),
                \Filament\Forms\Components\Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500)])
            ->fillForm(fn () => ['locale'=>Filament::getTenant()->fresh()->default_locale ?? config('clubunio-languages.default'),'expected'=>Filament::getTenant()->fresh()->default_locale])
            ->action(fn (array $data,$livewire) => \App\ClubUnio\Resources\FormMutation::run($livewire,fn () => app(\App\ClubUnio\Services\LanguagePreferences::class)->setTenantDefault(Filament::auth()->user(),Filament::getTenant(),$data))),
            Action::make('appProfiles')->label('App-Module nach Profil')->icon('heroicon-o-squares-2x2')
            ->visible(fn () => \App\ClubUnio\Resources\AccessProfileResource::canViewAny())
            ->url(fn () => \App\ClubUnio\Resources\AccessProfileResource::getUrl()),
            Action::make('mailDelivery')->label(__('mail_delivery.title'))->icon('heroicon-o-envelope')
                ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                ->fillForm(function () {
                    $tenant = Filament::getTenant();
                    abort_unless(BexioConnectionService::canManage(Filament::auth()->user(), $tenant), 403);

                    return ['reply_to' => app(\App\ClubUnio\Services\TenantMailSettings::class)->replyTo($tenant->id)];
                })
                ->schema([\Filament\Forms\Components\TextInput::make('reply_to')
                    ->label(__('mail_delivery.reply_to'))->helperText(__('mail_delivery.help'))->email()->maxLength(254)])
                ->action(function (array $data) {
                    app(\App\ClubUnio\Services\TenantMailSettings::class)->save(Filament::auth()->user(), Filament::getTenant(), $data['reply_to'] ?? null);
                    \Filament\Notifications\Notification::make()->title(__('mail_delivery.saved'))->success()->send();
                }),
            Action::make('bexio')->label('Bexio-Verbindung')->icon('heroicon-o-link')
            ->visible(fn () => BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->url(fn () => route('clubunio.bexio.show'))];
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
