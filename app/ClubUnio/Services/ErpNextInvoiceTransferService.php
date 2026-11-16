<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErpNextInvoiceTransferService
{
    public function __construct(private ErpNextConfiguration $configuration, private ErpNextClient $client) {}

    public static function query(Tenant $tenant): Builder
    {
        return DB::connection('clubunio')->table('clubora.erpnext_invoice_transfers')->where('tenant_id', $tenant->id);
    }

    public function transfer(Account $actor, Tenant $tenant, int $id, int $revision): object
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'send');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            $this->lockAndAuthorize($actor, $tenant, 'send');
            $invoice = $this->invoice($tenant, $id);
            $row = self::query($tenant)->where('invoice_id', $id)->first();
            if ($row) {
                return;
            }
            $config = $this->configuration->verifiedConnection($tenant);
            $bexio = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                ->where('tenant_id', $tenant->id)->where('kind', 'invoice')->where('invoice_id', $id)->exists();
            abort_if($bexio || $invoice->bexio_invoice_id, 409, trans('erpnext.errors.already_linked'));
            abort_unless((int) $invoice->revision === $revision && $invoice->workflowStatus?->code === 'released'
                && $invoice->active && ! $invoice->legacy_id, 409, trans('erpnext.errors.release'));
            abort_unless($invoice->tax_mode === 'exempt', 422, trans('erpnext.errors.tax'));
            $summary = app(InvoiceTaxService::class)->summary($invoice);
            abort_unless($summary['total_cents'] > 0, 422, trans('erpnext.errors.amount'));
            $this->billingAddress($tenant, $invoice);
            self::query($tenant)->insert([
                'tenant_id' => $tenant->id,
                'invoice_id' => $id,
                'reference' => $this->configuration->reference($tenant, 'invoice', $id),
                'origin' => $config['origin'],
                'total_cents' => $summary['total_cents'],
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
            $this->lockAndAuthorize($actor, $tenant, $create ? 'send' : 'view');
            $invoice = $this->invoice($tenant, $id);
            $row = self::query($tenant)->where('invoice_id', $id)->first();
            abort_unless($row, 404);
            $result = $this->syncLocked($tenant, $row, $create ? $invoice : null);
            abort_unless($result, 409, trans('erpnext.errors.pending'));

            return $result;
        });
    }

    public function reconcile(int $tenantId, int $id, CarbonImmutable $currentCutoff, CarbonImmutable $paidCutoff): string
    {
        return DB::connection('clubunio')->transaction(function () use ($tenantId, $id, $currentCutoff, $paidCutoff) {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->first();
            if (! $tenant || AccountingProvider::current($tenant) !== AccountingProvider::ERPNEXT) {
                return 'skipped';
            }
            $query = self::query($tenant)->where('invoice_id', $id);
            $row = $query->first();
            if (! $row || $row->state === 'cancelled') {
                return 'skipped';
            }
            $cutoff = $row->state === 'paid' && ! $row->status_sync_failed ? $paidCutoff : $currentCutoff;
            if ($row->last_status_attempt_at && CarbonImmutable::parse($row->last_status_attempt_at)->greaterThan($cutoff)) {
                return 'skipped';
            }
            try {
                if ($this->syncLocked($tenant, $row) === null) {
                    $query->update(['last_status_attempt_at' => now(), 'status_sync_failed' => false]);

                    return 'unconfirmed';
                }
                return 'confirmed';
            } catch (HttpExceptionInterface) {
                $query->update(['last_status_attempt_at' => now(), 'status_sync_failed' => true]);

                return 'failed';
            }
        });
    }

    private function syncLocked(Tenant $tenant, object $row, ?Invoice $invoice = null): ?object
    {
        $config = $this->configuration->verifiedConnection($tenant);
        $remote = $row->remote_id
            ? $this->client->get($config, 'Sales Invoice', $row->remote_id)
            : $this->client->find($config, 'Sales Invoice', $row->reference);
        if ($remote === null && $invoice !== null) {
            $payload = $this->payload($config, $tenant, $invoice, $row);
            $remote = $this->client->create($config, 'Sales Invoice', $payload);
            abort_unless((string) ($remote['docstatus'] ?? '') === '0'
                && ($remote['customer_address'] ?? null) === $payload['customer_address']
                && ($remote['company_address'] ?? null) === $payload['company_address'], 409, trans('erpnext.errors.identity'));
            if (isset($payload['custom_clubunio_qrr'])) {
                abort_unless(($remote['custom_clubunio_qrr'] ?? null) === $payload['custom_clubunio_qrr']
                    && ($remote['esr_reference'] ?? null) === $payload['custom_clubunio_qrr'], 409, trans('erpnext.errors.identity'));
            }
        }
        if ($remote === null) {
            return null;
        }
        $remoteId = $remote['name'] ?? '';
        abort_unless(is_string($remoteId) && $remoteId !== '' && strlen($remoteId) <= 140
            && ($row->remote_id === null || $row->remote_id === $remoteId)
            && ($remote['company'] ?? null) === $config['company']
            && ($remote['custom_clubunio_reference'] ?? null) === $row->reference
            && ($remote['currency'] ?? null) === 'CHF', 409, trans('erpnext.errors.identity'));
        $total = ErpNextClient::moneyCents(($remote['rounded_total'] ?? null) ?: ($remote['grand_total'] ?? null));
        abort_unless(ErpNextClient::moneyCents($remote['total_taxes_and_charges'] ?? 0) === 0, 409, trans('erpnext.errors.tax'));
        abort_unless($total === (int) $row->total_cents, 409, trans('erpnext.errors.amount'));
        $query = self::query($tenant)->where('invoice_id', $row->invoice_id);
        $query->update([
            'remote_id' => $remoteId,
            'state' => ErpNextClient::documentState($remote, $total),
            'last_status_attempt_at' => now(),
            'status_sync_failed' => false,
            'updated_at' => now(),
        ]);

        return $query->first();
    }

    private function payload(array $config, Tenant $tenant, Invoice $invoice, object $row): array
    {
        $member = $invoice->member;
        $addressFields = $this->billingAddress($tenant, $invoice);
        $name = trim((string) ($member->company ?: trim($member->first_name.' '.$member->last_name)));
        abort_if($name === '', 422, trans('erpnext.errors.identity'));
        $memberRef = $this->configuration->reference($tenant, 'member', (int) $member->id);
        $customer = $this->client->find($config, 'Customer', $memberRef)
            ?? $this->client->create($config, 'Customer', [
                'custom_clubunio_reference' => $memberRef,
                'customer_name' => $name,
                'customer_type' => $member->company ? 'Company' : 'Individual',
                'customer_group' => $config['customer_group'],
                'territory' => $config['territory'],
            ]);
        abort_unless(($customer['custom_clubunio_reference'] ?? null) === $memberRef && ! empty($customer['name']), 409, trans('erpnext.errors.identity'));
        $addressReference = $this->configuration->reference($tenant, 'address', (int) $member->id);
        $address = $this->client->find($config, 'Address', $addressReference)
            ?? $this->client->create($config, 'Address', $addressFields + [
                'custom_clubunio_reference' => $addressReference,
                'address_title' => $name,
                'address_type' => 'Billing',
                'links' => [['link_doctype' => 'Customer', 'link_name' => $customer['name']]],
            ]);
        abort_unless(($address['custom_clubunio_reference'] ?? null) === $addressReference
            && ! empty($address['name'])
            && collect($address['links'] ?? [])->contains(fn ($link) => ($link['link_doctype'] ?? null) === 'Customer'
                && ($link['link_name'] ?? null) === $customer['name']), 409, trans('erpnext.errors.identity'));

        $money = app(InvoiceWorkflowService::class);
        $summary = app(InvoiceTaxService::class)->summary($invoice);
        abort_unless($summary['total_cents'] === (int) $row->total_cents, 409, trans('erpnext.errors.amount'));
        $items = $invoice->activeLines()->orderBy('id')->get()->map(fn ($line) => [
            'item_code' => $config['item_code'],
            'income_account' => $config['income_account'],
            'description' => htmlspecialchars($line->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'qty' => 1,
            'rate' => $money->decimal($money->cents((string) $line->net_amount)),
        ])->all();
        // ERPNext's CHF rounding depends on System Settings; use the ClubUnio released amount.
        if ($summary['rounding_cents'] !== 0) {
            $rates = array_column($items, 'rate');
            $index = array_search(max($rates), $rates, true);
            $items[$index]['rate'] = $money->decimal($money->cents($items[$index]['rate']) + $summary['rounding_cents']);
        }
        $date = CarbonImmutable::parse($row->created_at)->setTimezone('Europe/Zurich')->startOfDay();

        $qrReference = config('clubunio-erpnext.qrr_enabled') ? ErpNextQrReference::fromInvoiceReference($row->reference) : null;

        return [
            'docstatus' => 0,
            'custom_clubunio_reference' => $row->reference,
            'company' => $config['company'],
            'company_address' => $config['company_address'],
            'customer' => $customer['name'],
            'customer_address' => $address['name'],
            'currency' => 'CHF',
            'conversion_rate' => 1,
            'posting_date' => $date->toDateString(),
            'due_date' => $date->addDays((int) $config['due_days'])->toDateString(),
            'ignore_pricing_rule' => 1,
            'disable_rounded_total' => 1,
            'taxes_and_charges' => '',
            'taxes' => [],
            'items' => $items,
        ] + ($qrReference === null ? [] : ['esr_reference' => $qrReference, 'custom_clubunio_qrr' => $qrReference]);
    }

    private function billingAddress(Tenant $tenant, Invoice $invoice): array
    {
        $member = $invoice->member;
        abort_unless((int) $member?->tenant_id === (int) $tenant->id, 409, trans('erpnext.errors.identity'));
        abort_unless(trim((string) $member->street) !== '' && trim((string) $member->postal_code) !== ''
            && trim((string) $member->city) !== '' && $member->country?->code === 'CH'
            && (int) $member->country?->tenant_id === (int) $tenant->id, 422, trans('erpnext.errors.address'));

        return [
            'address_line1' => trim($member->street.' '.$member->house_number),
            'pincode' => (string) $member->postal_code,
            'city' => (string) $member->city,
            'country' => 'Switzerland',
        ];
    }

    private function lockAndAuthorize(Account $actor, Tenant $tenant, string $ability): void
    {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', $ability);
        AccountingProvider::require($tenant, 'erpnext');
    }

    private function invoice(Tenant $tenant, int $id): Invoice
    {
        return Invoice::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
    }

}
