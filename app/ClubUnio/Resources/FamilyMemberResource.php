<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FamilyMemberResource extends FamilyResource
{
    protected static ?string $model = FamilyMember::class;

    protected static ?string $modelLabel = 'Familienzuordnung';

    protected static ?string $pluralModelLabel = 'Familienzuordnungen';

    protected static ?string $tenantRelationshipName = 'familyMembers';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Hidden::make('_version'), Select::make('family_id')->label('Familie')->required()->searchable()->options(fn (?FamilyMember $record) => Family::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->family_id ?? -1))->orderBy('name')->pluck('name', 'id')),
            MemberResource::memberSelect('member_id')->required(),
            TextInput::make('discount')->label('Familienrabatt')->numeric()->prefix('CHF')->default(0)->minValue(0)->required(),
            Toggle::make('active')->label('Aktiv')->default(true)->required(), Textarea::make('notes')->label('Bemerkungen')->maxLength(4000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('family.name')->label('Familie')->searchable()->sortable(),
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('discount')->label('Rabatt')->money('CHF'), TextColumn::make('notes')->label('Bemerkungen')->limit(80),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([
            SelectFilter::make('family_id')->label('Familie')->searchable()->options(fn () => Family::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id')),
            TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([static::createAction()])->recordActions([static::editAction()])
            ->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => FamilyMemberResource\Pages\ManageFamilyMembers::route('/')];
    }
}
