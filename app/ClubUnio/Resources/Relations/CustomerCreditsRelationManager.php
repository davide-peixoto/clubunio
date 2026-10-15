<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\CustomerCreditResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Table;

class CustomerCreditsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'customerCredits';

    protected static string $kind = 'customer_credits';

    protected static ?string $title = 'Kundenguthaben';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id)->with(['member', 'creditNote'])->withSum('allocations', 'amount_cents'))
            ->columns(CustomerCreditResource::columns())->recordActions([
                Action::make('view')->icon('heroicon-o-eye')->iconButton()->tooltip('Guthaben anzeigen')
                    ->url(fn ($record) => CustomerCreditResource::getUrl('view', ['record' => $record->id]))]);
    }
}
