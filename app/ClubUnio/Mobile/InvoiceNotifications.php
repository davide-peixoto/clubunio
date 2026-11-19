<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\BexioReconciliationService;
use Illuminate\Support\Facades\DB;

final class InvoiceNotifications
{
    /** Called ONLY for a freshly confirmed/applied provider check, never invoice creation/import. */
    public function confirmed(Tenant $tenant, int $checkId): void
    {
        if (! config('clubunio-mobile.enabled')) {
            return;
        }
        $db = DB::connection('clubunio');
        $db->transaction(function () use ($tenant, $checkId, $db) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $check = $db->table('clubunio.bexio_checks')->where('tenant_id', $tenant->id)->where('id', $checkId)->first();
            if (! $check || $check->object_type !== 'invoice' || $check->state !== 'applied'
                || ! in_array((string) $check->remote_status, ['8', '31'], true)
                || \Carbon\CarbonImmutable::parse($check->applied_at)->lt(now()->subMinutes(10))
                || app(BexioReconciliationService::class)->sandbox($tenant)) {
                return;
            }
            $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($check->object_id)->where('active', true)->first();
            if (! $invoice || $invoice->legacy_id || (string) $invoice->bexio_invoice_id !== (string) $check->remote_id) {
                return;
            }
            $balance = $db->table('clubunio.bexio_balances')->where('tenant_id', $tenant->id)->where('object_type', 'invoice')->where('object_id', $invoice->id)->first();
            if (! $balance || ! in_array((string) $balance->remote_status, ['8', '31'], true)
                || (string) $balance->remote_id !== (string) $invoice->bexio_invoice_id) {
                return;
            }
            // Once per invoice, including the transition open -> sent and repeated sync.
            $new = $db->table('clubunio.mobile_invoice_events')->insertOrIgnore(['tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id, 'observed_check_id' => $check->id, 'created_at' => now()->toIso8601String()]);
            if (! $new) {
                return;
            }
            $ids = $db->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)->where('member_id', $invoice->member_id)
                ->where('state', 'approved')->whereIn('kind', ['self', 'parent'])->distinct()->pluck('account_id');
            foreach (Account::whereIn('id', $ids)->where('active', true)->where('api_only', false)->get() as $actor) {
                if (! app(MemberAccessService::class)->canEnter($actor, $tenant)
                    || ! app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($invoice->member_id)->exists()) {
                    continue;
                }
                try {
                    app(MobileAccess::class)->invoice($actor, $tenant, (int) $invoice->id);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                    continue;
                }
                $db->table('clubunio.mobile_inbox')->insertOrIgnore(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                    'event_key' => 'invoice.available:'.$invoice->id, 'kind' => 'invoice', 'object_id' => $invoice->id, 'created_at' => now()->toIso8601String()]);
                if (config('clubunio-mobile-push.enabled', false)) {
                    app(MobilePushOutbox::class)->enqueue($actor, $tenant, 'invoice', (int) $invoice->id, 'invoice.available:'.$invoice->id);
                }
            }
        });
    }
}
