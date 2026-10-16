<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KeyReportResource extends CatalogResource
{
    protected static ?string $model = MemberKey::class;

    protected static ?string $pluralModelLabel = 'Schlüsselliste';

    protected static ?string $tenantRelationshipName = 'memberKeys';

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

        return parent::getEloquentQuery()->whereHas('member', fn ($q) => $q->where('tenant_id', Filament::getTenant()->id))
            ->with('member.status');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Schlüsselnummer')->searchable()->sortable(),
            TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(),
            TextColumn::make('color')->label('Farbe')->searchable(),
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('member.status.name')->label('Mitgliederstatus')->badge(),
            IconColumn::make('member.archived_at')->label('Mitglied archiviert')->boolean()
                ->getStateUsing(fn ($record) => $record->member?->archived_at !== null)->toggleable(),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([
            SelectFilter::make('color')->label('Farbe')->options(fn () => MemberKey::where('tenant_id', Filament::getTenant()->id)
                ->whereNotNull('color')->where('color', '<>', '')->distinct()->orderBy('color')->pluck('color', 'color')),
            TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([ReportExportService::action('keys')])->recordActions([
            ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')
                ->visible(fn ($record) => $record->member?->archived_at === null)
                ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
        ])->defaultSort('number')->paginated([25, 50, 100])
            ->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => KeyReportResource\Pages\ListKeys::route('/')];
    }
}
