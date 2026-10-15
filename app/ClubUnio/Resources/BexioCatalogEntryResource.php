<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioSandboxFixtureService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BexioCatalogEntryResource extends Resource
{
    protected static ?string $model = BexioCatalogEntry::class;

    protected static ?string $modelLabel = 'Bexio-Katalogeintrag';

    protected static ?string $pluralModelLabel = 'Bexio-Artikel und Steuern';

    protected static ?string $tenantRelationshipName = 'bexioCatalogEntries';

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-book-open';
    }

    public static function canViewAny(): bool
    {
        return Filament::getTenant() && BexioConnectionService::canManage(Filament::auth()->user()?->fresh(), Filament::getTenant());
    }

    public static function canCreate(): bool
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

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && (int) $record->tenant_id === (int) Filament::getTenant()->id
            && hash_equals($record->connection_hash, app(BexioSandboxFixtureService::class)->optionalConnectionHash(Filament::getTenant()) ?? '');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id ?? -1)
            ->where('connection_hash', Filament::getTenant() ? (app(BexioSandboxFixtureService::class)->optionalConnectionHash(Filament::getTenant()) ?? '') : '');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('revision')->label('Version')->readOnly()->required(),
            TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160),
            Toggle::make('active')->label('Lokal aktiviert')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kind')->label('Typ')->badge()->formatStateUsing(fn ($state) => $state === 'tax' ? 'Steuer' : 'Artikel')
                ->color(fn ($state) => $state === 'tax' ? 'warning' : 'info'),
            TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(),
            TextColumn::make('code')->label('Bexio-Code')->searchable(),
            TextColumn::make('remote_id')->label('Bexio-ID')->sortable(),
            TextColumn::make('rate_basis_points')->label('Satz')->formatStateUsing(fn ($state) => number_format($state / 100, 2, ',', '').' %'),
            TextColumn::make('valid_from')->label('Gültig ab')->date('d.m.Y'),
            TextColumn::make('valid_until')->label('Gültig bis')->date('d.m.Y'),
            IconColumn::make('provider_active')->label('In Bexio aktiv')->boolean(),
            IconColumn::make('active')->label('Lokal aktiviert')->boolean(),
            TextColumn::make('observed_at')->label('Abgeglichen')->dateTime('d.m.Y H:i'),
        ])->filters([
            SelectFilter::make('kind')->label('Typ')->options(['article' => 'Artikel', 'tax' => 'Steuern']),
            TernaryFilter::make('active')->label('Lokal aktiviert'),
            TernaryFilter::make('provider_active')->label('In Bexio aktiv'),
        ])->headerActions([
            Action::make('sync')->label('Aus Bexio aktualisieren')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->action(fn ($livewire) => $livewire->syncCatalog()),
        ])->recordActions([
            EditAction::make()->iconButton()->tooltip('Bezeichnung und Aktivierung bearbeiten')
                ->using(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(BexioCatalogService::class)->edit(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
        ])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()->defaultSort('kind');
    }

    public static function getPages(): array
    {
        return ['index' => BexioCatalogEntryResource\Pages\ManageRecords::route('/')];
    }
}
