<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ErpNextExpenseTransferService
{
    public function __construct(
        private ErpNextConfiguration $configuration,
        private ErpNextClient $client,
        private ErpNextMemberSyncService $members,
    ) {}

    public static function query(Tenant $tenant): Builder
    {
        return DB::connection('clubunio')->table('clubora.erpnext_expense_transfers')->where('tenant_id', $tenant->id);
    }

    public static function approved(Expense $expense): bool
    {
        $status = $expense->status;

        return $expense->active && $expense->legacy_id === null && $expense->payment_released_on && ! $expense->paid_on
            && $status?->active && $status->code === 'Z' && (int) $status->tenant_id === (int) $expense->tenant_id;
    }

    public function transfer(Account $actor, Tenant $tenant, int $id, int $revision): object
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            $expense = $this->lockExpense($actor, $tenant, $id, 'approve');
            if (self::query($tenant)->where('expense_id', $id)->exists()) {
                return;
            }
            $this->ensureApproved($expense, $revision);
            $bexio = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                ->where('tenant_id', $tenant->id)->where('kind', 'expense')->where('expense_id', $id)->exists();
            abort_if($bexio || $expense->bexio_bill_id, 409, trans('erpnext.errors.expense_already_linked'));
            $config = $this->configuration->verifiedConnection($tenant);
            $this->ensureMapping($config);
            $this->payee($tenant, $expense);
            [, $total] = $this->items($expense, $config);
            self::query($tenant)->insert([
                'tenant_id' => $tenant->id,
                'expense_id' => $id,
                'source_revision' => $revision,
                'reference' => $this->configuration->reference($tenant, 'expense', $id),
                'origin' => $config['origin'],
                'total_cents' => $total,
            ]);
        });

        return $this->sync($actor, $tenant, $id, true);
    }

    public function refresh(Account $actor, Tenant $tenant, int $id): object
    {
        return $this->sync($actor, $tenant, $id, false);
    }

    private function sync(Account $actor, Tenant $tenant, int $id, bool $create): object
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $create) {
            $expense = $this->lockExpense($actor, $tenant, $id, $create ? 'approve' : 'view');
            $query = self::query($tenant)->where('expense_id', $id);
            $row = (clone $query)->first() ?? abort(404);
            $config = $this->configuration->verifiedConnection($tenant);
            abort_unless($config['origin'] === $row->origin, 409, trans('erpnext.errors.destination'));
            $remote = $row->remote_id
                ? $this->client->get($config, 'Purchase Invoice', $row->remote_id)
                : $this->client->find($config, 'Purchase Invoice', $row->reference);
            if ($remote === null && $create) {
                $this->ensureApproved($expense, (int) $row->source_revision);
                $this->ensureMapping($config);
                $remote = $this->client->create($config, 'Purchase Invoice', $this->payload($config, $tenant, $expense, $row));
                abort_unless((string) ($remote['docstatus'] ?? '') === '0' && (int) ($remote['is_paid'] ?? 0) === 0
                    && (int) ($remote['update_stock'] ?? 0) === 0, 409, trans('erpnext.errors.identity'));
            }
            abort_unless($remote !== null, 409, trans('erpnext.errors.pending'));
            $remoteId = $remote['name'] ?? '';
            abort_unless(is_string($remoteId) && $remoteId !== '' && strlen($remoteId) <= 140
                && ($row->remote_id === null || $row->remote_id === $remoteId)
                && ($remote['company'] ?? null) === $config['company']
                && ($remote['custom_clubunio_reference'] ?? null) === $row->reference
                && ($remote['currency'] ?? null) === 'CHF'
                && (int) ($remote['is_return'] ?? 0) === 0, 409, trans('erpnext.errors.identity'));
            $total = ErpNextClient::moneyCents(($remote['rounded_total'] ?? null) ?: ($remote['grand_total'] ?? null));
            abort_unless($total === (int) $row->total_cents, 409, trans('erpnext.errors.expense_amount'));
            abort_unless(ErpNextClient::moneyCents($remote['total_taxes_and_charges'] ?? 0) === 0, 409, trans('erpnext.errors.expense_tax'));
            (clone $query)->update([
                'remote_id' => $remoteId,
                'state' => ErpNextClient::documentState($remote, $total),
                'updated_at' => now(),
            ]);

            return $query->first();
        });
    }

    private function lockExpense(Account $actor, Tenant $tenant, int $id, string $ability): Expense
    {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $permissions = app(ModulePermissionService::class);
        $permissions->authorize($actor, $tenant, 'expenses', 'view');
        if ($ability !== 'view') {
            $permissions->authorize($actor, $tenant, 'expenses', $ability);
        }
        AccountingProvider::require($tenant, AccountingProvider::ERPNEXT);

        return app(ExpenseService::class)->visible($actor, $tenant)->lockForUpdate()->findOrFail($id);
    }

    private function ensureApproved(Expense $expense, int $revision): void
    {
        abort_unless(self::approved($expense) && (int) $expense->revision === $revision, 409, trans('erpnext.errors.expense_approved'));
    }

    private function ensureMapping(array $config): void
    {
        abort_unless(! empty($config['supplier_group']) && ! empty($config['expense_item_code']) && ! empty($config['expense_account']),
            422, trans('erpnext.errors.expense_mapping'));
    }

    private function payload(array $config, Tenant $tenant, Expense $expense, object $row): array
    {
        [$items, $total] = $this->items($expense, $config);
        abort_unless($total === (int) $row->total_cents, 409, trans('erpnext.errors.expense_amount'));
        $supplier = $this->members->supplierForExpense($config, $tenant, $this->payee($tenant, $expense));
        $posted = CarbonImmutable::parse($row->created_at)->setTimezone('Europe/Zurich');

        return array_filter([
            'docstatus' => 0,
            'is_paid' => 0,
            'update_stock' => 0,
            'custom_clubunio_reference' => $row->reference,
            'bill_no' => $row->reference,
            'company' => $config['company'],
            'billing_address' => $config['company_address'],
            'supplier' => $supplier['supplier'],
            'supplier_address' => $supplier['supplier_address'],
            'currency' => 'CHF',
            'conversion_rate' => 1,
            'posting_date' => $posted->toDateString(),
            'due_date' => $posted->addDays((int) $config['due_days'])->toDateString(),
            'ignore_pricing_rule' => 1,
            'disable_rounded_total' => 1,
            'taxes_and_charges' => '',
            'taxes' => [],
            'items' => $items,
        ], fn ($value) => $value !== null);
    }

    private function payee(Tenant $tenant, Expense $expense): Member
    {
        $member = $expense->member;
        abort_unless($member && (int) $member->tenant_id === (int) $tenant->id
            && trim((string) ($member->company ?: ($member->first_name.' '.$member->last_name))) !== '',
            409, trans('erpnext.errors.identity'));

        return $member;
    }

    private function items(Expense $expense, array $config): array
    {
        $lines = $expense->activeLines()->where('tenant_id', $expense->tenant_id)->with('expenseType')->orderBy('id')->get();
        abort_if($lines->isEmpty(), 422, trans('erpnext.errors.expense_amount'));
        $money = app(InvoiceWorkflowService::class);
        $items = [];
        $running = BigDecimal::zero();
        $total = 0;
        foreach ($lines as $line) {
            abort_unless($line->amount !== null, 422, trans('erpnext.errors.expense_amount'));
            $running = $running->plus($line->amount);
            $rounded = ErpNextClient::moneyCents((string) $running);
            $cents = $rounded - $total;
            abort_unless($cents > 0, 422, trans('erpnext.errors.expense_amount'));
            $total = $rounded;
            $type = $line->expenseType;
            $description = $type && (int) $type->tenant_id === (int) $expense->tenant_id
                ? $type->name : trans('erpnext.expense_item');
            $items[] = [
                'item_code' => $config['expense_item_code'],
                'description' => e($description),
                'expense_account' => $config['expense_account'],
                'qty' => 1,
                'rate' => $money->decimal($cents),
            ];
        }

        return [$items, $total];
    }
}
