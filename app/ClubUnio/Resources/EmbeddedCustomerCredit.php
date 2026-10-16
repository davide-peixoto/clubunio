<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\CustomerCreditResource\Pages\ViewCustomerCredit;

class EmbeddedCustomerCredit extends ViewCustomerCredit
{
    use EmbeddedFinancialRecord;

    protected string $view = 'clubunio.finance.embedded-record';

    protected const DOSSIER_MODEL = Member::class;

    protected const DOSSIER_FIELD = 'member_id';
}
