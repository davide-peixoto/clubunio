<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireMobileExpenseOcr extends Command
{
    protected $signature = 'clubunio:expire-mobile-expense-ocr';
    protected $description = 'Remove private OCR results after the receipt draft lifetime; retain cost reservations.';

    public function handle(): int
    {
        $count = DB::connection('clubunio')->table('clubora.mobile_ai_work')
            ->where('operation', 'expense_ocr')->where('provider', 'azure_document_intelligence')
            ->where('created_at', '<=', now()->subHours(24))
            ->whereNotNull('result_encrypted')->update(['result_encrypted' => null, 'state' => 'expired']);
        $this->info((string) $count.' private OCR results expired.');
        return self::SUCCESS;
    }
}
