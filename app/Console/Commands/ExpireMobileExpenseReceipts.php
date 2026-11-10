<?php

namespace App\Console\Commands;

use App\ClubUnio\Mobile\MobileExpenseReceipts;
use App\ClubUnio\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireMobileExpenseReceipts extends Command
{
    protected $signature = 'clubunio:expire-mobile-expense-receipts';
    protected $description = 'Remove expired encrypted personal expense receipt draft bytes.';
    public function handle(): int {
        $db = DB::connection('clubunio');
        if (! $db->getSchemaBuilder()->hasTable('clubunio.mobile_expense_receipts')) { $this->info('Expense receipt drafts not installed.'); return self::SUCCESS; }
        $count = 0;
        $affected = $db->table('clubunio.mobile_expense_receipts')->where('state', 'clean')->where('expires_at', '<=', now()->toIso8601String())->select('tenant_id');
        foreach (Tenant::query()->whereIn('id', $affected)->orderBy('id')->cursor() as $tenant) {
            $count += $db->transaction(function () use ($tenant) {
                DB::connection('clubunio')->statement("SET LOCAL lock_timeout TO '5s'");
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                return app(MobileExpenseReceipts::class)->expire($tenant);
            });
        }
        $this->info('Expired draft byte stores cleared: '.$count);
        return self::SUCCESS;
    }
}
