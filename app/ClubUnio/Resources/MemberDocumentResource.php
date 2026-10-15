<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\MemberDocument;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MemberDocumentResource extends CatalogResource
{
    protected static ?string $model = MemberDocument::class;

    protected static ?string $modelLabel = 'Mitgliederdokument';

    protected static ?string $pluralModelLabel = 'Mitgliederdokumente';

    protected static ?string $tenantRelationshipName = 'memberDocuments';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('documentType.name')->label('Dokumententyp')->sortable(),
            TextColumn::make('original_filename')->label('Dateiname')->searchable()->wrap(),
            TextColumn::make('file_size')->label('Bytes')->numeric(), TextColumn::make('content_type')->label('Dateityp'),
            IconColumn::make('portal_visible')->label('Portal-Freigabe')->boolean(),
            TextColumn::make('source_created_at')->label('Erstellt')->dateTime('d.m.Y H:i'),
        ])->filters([
            SelectFilter::make('document_type_id')->label('Dokumententyp')->options(fn () => DocumentType::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id')),
            TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([MemberDocumentActions::upload()])->recordActions([...DocumentActions::make(), MemberDocumentActions::manage()])->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => MemberDocumentResource\Pages\ListMemberDocuments::route('/')];
    }
}
