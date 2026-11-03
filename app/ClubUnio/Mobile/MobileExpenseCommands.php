<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, ExpenseLine, Tenant};
use App\ClubUnio\Services\ExpenseService;
use Illuminate\Support\Facades\DB;

final class MobileExpenseCommands
{
    private function table(Account $actor, Tenant $tenant) { return DB::connection('clubunio')->table('clubora.mobile_expense_commands')->where('tenant_id', $tenant->id)->where('account_id', $actor->id); }
    public function fingerprint(array $input): string {
        $normalize = function ($value, $field = null) use (&$normalize) {
            if (in_array($field, ['member_id','team_season_id','bank_account_id','round_id','expense_id','expense_type_id','game_type_id','revision','expense_revision','receipt_revision'], true) && $value !== null) return (int) $value;
            if ($field === 'amount' && is_scalar($value)) {
                [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
                return ((string) (int) $whole).'.'.str_pad(substr($fraction, 0, 4), 4, '0');
            }
            if ($field === 'receipt_token' && is_string($value)) return strtolower($value);
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value);
            foreach ($value as $key => $item) $value[$key] = $normalize($item, $key);
            return $value;
        };
        return hash('sha256', json_encode($normalize($input), JSON_THROW_ON_ERROR));
    }
    public function replay(Account $actor, Tenant $tenant, string $key, string $operation, string $fingerprint): ?array {
        $row = $this->table($actor, $tenant)->where('command_key', $key)->first();
        if (! $row) return null;
        abort_unless($row->operation === $operation && hash_equals($row->fingerprint, $fingerprint), 409, 'Dieser Auftrag wurde bereits mit anderen Werten verwendet.');
        app(ExpenseService::class)->visible($actor, $tenant)->findOrFail($row->expense_id);
        if ($row->line_id) ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('expense_id', $row->expense_id)->where('active', true)->findOrFail($row->line_id);
        return app(MobileExpenseData::class)->detail($actor, $tenant, (int) $row->expense_id) + ['line_id' => $row->line_id ? (int) $row->line_id : null,
            'upload_id' => $row->upload_id ? (int) $row->upload_id : null, 'replayed' => true];
    }
    public function remember(Account $actor, Tenant $tenant, string $key, string $operation, string $fingerprint, int $expense, ?int $line = null, ?int $upload = null): void {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 500);
        $this->table($actor, $tenant)->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'command_key' => $key,
            'operation' => $operation, 'fingerprint' => $fingerprint, 'expense_id' => $expense, 'line_id' => $line, 'upload_id' => $upload]);
    }
}
