<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\ContributionRuleResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContributionRulesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'contributionRules';

    protected static string $kind = 'contributionRules';

    protected static ?string $title = 'Beitragsregeln';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id))
            ->columns([TextColumn::make('price.name')->label('Beitrag')->searchable(), TextColumn::make('price.amount')->label('Betrag')->money('CHF'), TextColumn::make('gender.name')->label('Geschlecht'), TextColumn::make('year_from')->label('Jahrgang von'), TextColumn::make('year_to')->label('Jahrgang bis')])->headerActions([Action::make('rules')->label('Beitragsregeln bearbeiten')->icon('heroicon-o-arrow-top-right-on-square')->url(fn () => ContributionRuleResource::getUrl('index', ['tableFilters[season_id][value]' => $this->getOwnerRecord()->id]))])->paginated([10, 25, 50]);
    }
}
