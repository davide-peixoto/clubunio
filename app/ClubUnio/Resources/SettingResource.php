<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SettingsService;
use App\ClubUnio\Services\SettingOptions;
use Filament\Forms\Components\Select;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SettingResource extends Resource
{
    use LocalizedLabels;
    protected static ?string $model = Setting::class;

    protected static ?string $modelLabel = 'Einstellung';

    protected static ?string $pluralModelLabel = 'Einstellungen';

    protected static ?string $tenantRelationshipName = 'settings';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id ?? 0)->where('area', '!=', 'mail');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('area')->label('Bereich')->badge()->sortable(),
            TextColumn::make('label')->label('Bezeichnung')->searchable()->wrap(),
            TextColumn::make('key')->label('Schlüssel')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('display_value')->label('Wert')->state(fn (Setting $record) => app(SettingOptions::class)->display($record))->description(fn (Setting $record) => $record->area === 'general' ? app(SettingOptions::class)->hint($record) : null)->limit(60)->wrap(),
            TextColumn::make('is_secret')->label('Schutz')->formatStateUsing(fn ($state) => $state ? __('Vertraulich') : __('Standard')),
            TextColumn::make('usage')->label('Verwendung / Freigabe')->state(fn (Setting $record) => app(SettingOptions::class)->usage($record))->badge()
                ->color(fn (Setting $record) => ! app(SettingOptions::class)->archived($record)
                    && (($record->area === 'general' && in_array($record->key, ['SaisonID', \App\ClubUnio\Services\ActiveTrainingPlan::KEY], true)) || $record->enabled)
                        ? 'success' : 'gray')
                ->tooltip(fn (Setting $record) => app(SettingOptions::class)->hint($record)),
            TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('area')->label('Bereich')->options(['general' => __('Allgemein'), 'app' => __('App'), 'bexio' => __('Bexio'), 'integrations' => __('Integrationen'), 'public' => __('Öffentliche Seiten'), 'signage' => __('Signage-Design')]),
        ])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()
            ->recordActions([
                Action::make('instagram')->label('Instagram-Feed')->icon('heroicon-o-adjustments-horizontal')->iconButton()->tooltip(__('Instagram-Feed aktivieren/deaktivieren'))
                    ->visible(fn (Setting $record) => $record->key === 'INSTAGRAM_ACCESS_TOKEN' && $record->area === 'integrations' && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                    ->fillForm(fn (Setting $record) => ['enabled' => (bool) $record->enabled, 'expected' => (bool) $record->enabled])
                    ->schema([Toggle::make('enabled')->label('Instagram anzeigen')->required(), Hidden::make('expected')->required()->rules(['boolean'])])
                    ->action(function (Setting $record, array $data) {
                        app(SettingsService::class)->enableInstagram(Filament::auth()->user(), Filament::getTenant(), $record->id, (bool) $data['enabled'], (bool) $data['expected']);
                        Notification::make()->title(__('Instagram-Einstellung gespeichert'))->success()->send();
                    }),
                Action::make('change')->label('Wert ersetzen')->icon('heroicon-o-pencil-square')->iconButton()->tooltip(__('Wert ersetzen'))
                    ->visible(fn (Setting $record) => ! app(SettingOptions::class)->archived($record) && $record->key !== BexioConnectionService::CONNECTION_KEY
                        && ($record->key !== \App\ClubUnio\Services\ActiveTrainingPlan::KEY || BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
                        && (! in_array($record->key, ['BEXIO_CLIENT_ID', 'BEXIO_CLIENT_SECRET', 'BEXIO_AUTHORIZE_SCOPES'], true)
                            || BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant())))
                    ->fillForm(fn (Setting $record) => ['value' => $record->is_secret ? '' : Crypt::decryptString($record->encrypted_value)])
                    ->schema(fn (Setting $record) => app(SettingOptions::class)->options($record) !== null
                        ? [Select::make('value')->label('Neuer Wert')->options(app(SettingOptions::class)->options($record))->searchable()->required()->helperText(app(SettingOptions::class)->hint($record))]
                        : [TextInput::make('value')->label('Neuer Wert')->password($record->is_secret)->required()->maxLength(65535)])
                    ->action(function (Setting $record, array $data) {
                        app(SettingsService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $data['value']);
                        Notification::make()->title(__('Einstellung gespeichert'))->success()->send();
                    }),
            ])->defaultSort('area')->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => SettingResource\Pages\ListSettings::route('/')];
    }
}
