<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Resources\CreditNoteResource\Pages\ViewCreditNote;

class EmbeddedCreditNote extends ViewCreditNote
{
    use EmbeddedFinancialRecord;

    protected string $view = 'clubunio.finance.embedded-record';

    protected const DOSSIER_MODEL = Invoice::class;

    protected const DOSSIER_FIELD = 'invoice_id';

    protected function getHeaderActions(): array
    {
        return array_map(function ($action) {
            if ($action->getName() === 'viewCustomerCredit') {
                return FinancialDossierDialog::make(EmbeddedCustomerCredit::class, fn () => $this->getRecord()->member_id,
                    fn () => $this->getRecord()->customerCredit?->id, 'viewCustomerCredit')
                    ->label('Kundenguthaben anzeigen')->visible(fn () => $this->getRecord()->customerCredit !== null);
            }

            return $action;
        }, parent::getHeaderActions());
    }
}
