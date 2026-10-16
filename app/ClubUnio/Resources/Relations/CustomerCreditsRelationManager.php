<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\CustomerCreditResource;
use App\ClubUnio\Resources\EmbeddedCustomerCredit;
use App\ClubUnio\Resources\FinancialDossierDialog;
use Filament\Facades\Filament;
use Filament\Tables\Table;

class CustomerCreditsRelationManager extends DossierRelationManager
{
    use RefreshFinancialDossier;

    protected static string $relationship = 'customerCredits';

    protected static string $kind = 'customer_credits';

    protected static ?string $title = 'Kundenguthaben';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id)->with(['member', 'creditNote'])->withSum('allocations', 'amount_cents'))
            ->columns(CustomerCreditResource::columns())->recordActions([
                FinancialDossierDialog::make(EmbeddedCustomerCredit::class, fn () => $this->getOwnerRecord()->id)]);
    }
}
