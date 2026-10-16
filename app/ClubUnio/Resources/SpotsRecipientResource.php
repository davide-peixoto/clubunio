<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\SpotsRecipient;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SpotsRecipientResource extends CatalogResource
{
    protected static ?string $model = SpotsRecipient::class;

    protected static ?string $pluralModelLabel = 'Spots-Versandliste';

    protected static ?string $tenantRelationshipName = 'spotsRecipients';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canViewAny(): bool
    {
        return app(BackofficeReadContext::class)->allowsReadPage(Filament::auth()->user(), Filament::getTenant());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        abort_unless(static::canViewAny(), 403);

        return parent::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kind')->label('Empfänger')->formatStateUsing(fn ($state) => $state === 'family' ? 'Familie' : 'Einzelmitglied')->badge(),
            TextColumn::make('company')->label('Firma')->searchable()->toggleable(),
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('street')->label('Strasse')->searchable(),
            TextColumn::make('postal_code')->label('PLZ')->searchable()->sortable(),
            TextColumn::make('city')->label('Ort')->searchable()->sortable(),
            TextColumn::make('country')->label('Land')->toggleable(),
            TextColumn::make('member_type')->label('Mitgliederart')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('member_status')->label('Mitgliederstatus')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('kind')->label('Empfänger')->options(['member' => 'Einzelmitglied', 'family' => 'Familie']),
        ])->headerActions([ReportExportService::action('spots')])->recordActions([
            ViewAction::make()->iconButton()->tooltip('Dossier')->url(fn ($record) => $record->kind === 'family'
                ? FamilyResource::getUrl('view', ['record' => $record->family_id])
                : MemberResource::getUrl('view', ['record' => $record->member_id])),
        ])->defaultSort('name')->paginated([25, 50, 100])
            ->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => SpotsRecipientResource\Pages\ListRecipients::route('/')];
    }
}
