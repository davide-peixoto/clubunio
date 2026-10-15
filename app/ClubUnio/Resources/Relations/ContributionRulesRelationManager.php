<?php

namespace App\ClubUnio\Resources\Relations;

use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContributionRulesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'contributionRules';

    protected static string $kind = 'contributionRules';

    protected static ?string $title = 'Beitragsregeln';

    public function table(Table $table): Table
    {
        return $this->withAssignmentActions($table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id))
            ->columns([TextColumn::make('price.name')->label('Beitrag')->searchable(), TextColumn::make('price.amount')->label('Betrag')->money('CHF'), TextColumn::make('gender.name')->label('Geschlecht'), TextColumn::make('year_from')->label('Jahrgang von'), TextColumn::make('year_to')->label('Jahrgang bis'), IconColumn::make('active')->label('Aktiv')->boolean()])->paginated([10, 25, 50]));
    }
}
