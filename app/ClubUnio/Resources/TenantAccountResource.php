<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\TenantAccount;
use App\ClubUnio\Services\AccountAdministrationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantAccountResource extends CatalogResource
{
    protected static ?string $model = TenantAccount::class;

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer und Rollen';

    protected static ?string $tenantRelationshipName = 'tenantAccounts';

    public static function getNavigationGroup(): ?string
    {
        return 'Verwaltung';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function canViewAny(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $actor && $tenant && app(AccountAdministrationService::class)->allowed($actor, $tenant);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function roles(): array
    {
        return AccountRole::where('active', true)->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    public static function fields(bool $edit): array
    {
        return [TextInput::make('username')->label('Benutzername')->required(! $edit)->disabled($edit)->dehydrated(! $edit)->maxLength(255),
            TextInput::make('display_name')->label('Anzeigename')->required()->maxLength(255),
            Select::make('role')->label('Systemrolle')->options(self::roles())->default('member')->required()->live()
                ->afterStateUpdated(function (?string $state, Set $set) {
                    $set('access_profile_id', null);
                    if ($state === 'profile') {
                        $set('portal_enabled', false);
                    }
                }),
            Select::make('access_profile_id')->label('Berechtigungsprofil')->searchable()
                ->visible(fn (Get $get) => $get('role') === 'profile')->required(fn (Get $get) => $get('role') === 'profile')
                ->dehydrated(fn (Get $get) => $get('role') === 'profile')
                ->options(fn (Get $get) => $get('role') === 'profile'
                    ? AccessProfile::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('name')->pluck('name', 'id')->all() : []),
            Toggle::make('active')->label('Konto aktiv')->default(false)->required(),
            Toggle::make('portal_enabled')->label('Mitgliederportal freigegeben')->default(false)->required()
                ->disabled(fn (Get $get) => $get('role') === 'profile')->dehydrated(),
            TextInput::make('password')->label($edit ? 'Neues Passwort' : 'Passwort')->password()->autocomplete('new-password')->required(! $edit)->minLength(12)->maxLength(72)->confirmed(),
            TextInput::make('password_confirmation')->label('Passwort bestätigen')->password()->autocomplete('new-password'),
            Textarea::make('reason')->label('Begründung')->required()->minLength(10)->maxLength(500),
            Hidden::make('revision')->dehydrated($edit), Hidden::make('account_revision')->dehydrated($edit)];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('account.username')->label('Benutzername')->searchable()->sortable(),
            TextColumn::make('account.display_name')->label('Name')->searchable()->sortable(),
            TextColumn::make('roleDefinition.name')->label('Rolle')->badge(),
            IconColumn::make('account.active')->label('Aktiv')->boolean(), IconColumn::make('portal_enabled')->label('Portal')->boolean(),
            TextColumn::make('legacy_user_level')->label('Bisheriger Userlevel')->toggleable(isToggledHiddenByDefault: true)])
            ->filters([SelectFilter::make('role')->label('Rolle')->options(self::roles()), TernaryFilter::make('portal_enabled')->label('Portal'),
                TernaryFilter::make('enabled')->label('Konto aktiv')->queries(true: fn ($q) => $q->whereHas('account', fn ($a) => $a->where('active', true)), false: fn ($q) => $q->whereHas('account', fn ($a) => $a->where('active', false)))])
            ->headerActions([Action::make('newAccount')->label('Benutzer hinzufügen')->icon('heroicon-o-user-plus')->schema(self::fields(false))
                ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountAdministrationService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data)))])
            ->recordActions([Action::make('manage')->label('Benutzer bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Benutzer und Rolle bearbeiten')->schema(self::fields(true))
                ->fillForm(fn (TenantAccount $record) => ['username' => $record->account->username, 'display_name' => $record->account->display_name, 'active' => $record->account->active, 'role' => $record->role, 'access_profile_id' => $record->access_profile_id, 'portal_enabled' => $record->portal_enabled, 'revision' => $record->revision, 'account_revision' => $record->account->revision])
                ->action(fn (TenantAccount $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountAdministrationService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)))])
            ->defaultSort('id')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => TenantAccountResource\Pages\ManageAccounts::route('/')];
    }
}
