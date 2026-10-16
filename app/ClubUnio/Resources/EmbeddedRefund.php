<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Resources\RefundResource\Pages\ViewRefund;

class EmbeddedRefund extends ViewRefund
{
    use EmbeddedFinancialRecord;

    protected string $view = 'clubunio.finance.embedded-record';

    protected const DOSSIER_MODEL = Invoice::class;

    protected const DOSSIER_FIELD = 'invoice_id';
}
