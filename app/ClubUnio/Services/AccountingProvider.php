<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingProvider
{
    public const NONE = 'none';

    public const BEXIO = 'bexio';

    public const ERPNEXT = 'erpnext';

    public const OPTIONS = [self::NONE, self::BEXIO, self::ERPNEXT];

    public static function current(Tenant $tenant): string
    {
        return (string) Tenant::query()->whereKey($tenant->id)->value('accounting_provider');
    }

    public static function require(Tenant $tenant, string $provider): void
    {
        abort_unless(self::current($tenant) === $provider, 409, trans('accounting_provider.inactive'));
    }

    public function choose(Account $actor, Tenant $tenant, string $provider, string $expected): void
    {
        abort_unless(in_array($provider, self::OPTIONS, true), 422);
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $db = DB::connection('clubunio');
        $db->transaction(function () use ($actor, $tenant, $provider, $expected) {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor, $locked), 403);
            if ($locked->accounting_provider !== $expected) {
                throw ValidationException::withMessages(['provider' => trans('accounting_provider.changed')]);
            }
            if ($provider === $expected) {
                return;
            }
            if ($this->hasOpenTransfers($locked, $expected)) {
                throw ValidationException::withMessages(['provider' => trans('accounting_provider.open_transfers')]);
            }
            $locked->accounting_provider = $provider;
            $locked->save();
        });
    }

    private function hasOpenTransfers(Tenant $tenant, string $provider): bool
    {
        $db = DB::connection('clubunio');
        if ($provider === self::ERPNEXT) {
            return ErpNextInvoiceTransferService::query($tenant)->where('state', 'pending')->exists()
                || ErpNextExpenseTransferService::query($tenant)->where('state', 'pending')->exists();
        }
        if ($provider !== self::BEXIO) {
            return false;
        }

        return $db->table('clubora.bexio_sandbox_dispatches')
            ->where('tenant_id', $tenant->id)->whereIn('state', ['ready', 'running', 'uncertain'])->exists()
            || $db->table('clubora.sync_work_items')
                ->where('tenant_id', $tenant->id)->whereIn('operation', SyncWorkService::BEXIO_OPERATIONS)
                ->whereIn('state', ['pending', 'running', 'attention'])->exists()
            || $db->table('clubora.api_provider_work')
                ->where('tenant_id', $tenant->id)->where('operation', 'like', 'bexio.%')
                ->whereIn('state', ['pending', 'awaiting_approval', 'running'])->exists()
            || $db->table('clubora.bexio_sandbox_recipients')
                ->where('tenant_id', $tenant->id)->whereIn('state', ['ready', 'running', 'uncertain'])->exists()
            || $db->table('clubora.bexio_catalog_fixture_actions')
                ->where('tenant_id', $tenant->id)->whereIn('state', ['running', 'uncertain'])->exists()
            || $db->table('clubora.bexio_invoice_actions as action')
                ->join('clubora.bexio_sandbox_dispatches as dispatch', 'dispatch.id', '=', 'action.dispatch_id')
                ->where('dispatch.tenant_id', $tenant->id)->whereIn('action.state', ['running', 'uncertain'])->exists()
            || $db->table('clubora.bexio_financial_actions as action')
                ->join('clubora.bexio_sandbox_dispatches as dispatch', 'dispatch.id', '=', 'action.dispatch_id')
                ->where('dispatch.tenant_id', $tenant->id)->whereIn('action.state', ['running', 'uncertain'])->exists()
            || $db->table('clubora.sponsor_run_invoices')
                ->where('tenant_id', $tenant->id)
                ->where(fn ($query) => $query->whereNotNull('operation_token')->orWhere('state', 'like', '%_uncertain'))
                ->exists();
    }
}
