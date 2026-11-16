<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
            $config = $this->connection($tenant);
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
            $config = $this->connection($tenant);

            $remote = $row->remote_id
                ? $this->client->get($config, 'Sales Invoice', $row->remote_id)
                : $this->client->find($config, 'Sales Invoice', $row->reference);
            if ($remote === null && $create) {
                $payload = $this->payload($config, $tenant, $invoice, $row);
                $remote = $this->client->create($config, 'Sales Invoice', $payload);
                abort_unless((string) ($remote['docstatus'] ?? '') === '0'
                    && ($remote['customer_address'] ?? null) === $payload['customer_address']
                    && ($remote['company_address'] ?? null) === $payload['company_address'], 409, trans('erpnext.errors.identity'));
            }
            abort_unless($remote !== null, 409, trans('erpnext.errors.pending'));
            $remoteId = $remote['name'] ?? '';
            abort_unless(is_string($remoteId) && $remoteId !== '' && strlen($remoteId) <= 140
                && ($row->remote_id === null || $row->remote_id === $remoteId)
                && ($remote['company'] ?? null) === $config['company']
                && ($remote['custom_clubunio_reference'] ?? null) === $row->reference
                && ($remote['currency'] ?? null) === 'CHF', 409, trans('erpnext.errors.identity'));
            $total = self::moneyCents(($remote['rounded_total'] ?? null) ?: ($remote['grand_total'] ?? null));
            abort_unless(self::moneyCents($remote['total_taxes_and_charges'] ?? 0) === 0, 409, trans('erpnext.errors.tax'));
            abort_unless($total === (int) $row->total_cents, 409, trans('erpnext.errors.amount'));
            self::query($tenant)->where('invoice_id', $id)->update([
                'remote_id' => $remoteId,
                'state' => self::state($remote, $total),
                'updated_at' => now(),
            ]);

            return self::query($tenant)->where('invoice_id', $id)->first();
        });
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
        ];
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

    private function connection(Tenant $tenant): array
    {
        $config = $this->configuration->connection($tenant);
        abort_unless($config && ($config['origin'] ?? null) === $this->configuration->origin(), 409, trans('erpnext.errors.destination'));

        return $config;
    }

    private static function moneyCents(mixed $amount): int
    {
        abort_unless(is_int($amount) || is_float($amount) || is_string($amount), 409, trans('erpnext.errors.amount'));
        try {
            return BigDecimal::of((string) $amount)->multipliedBy(100)->toScale(0, RoundingMode::HALF_UP)->toInt();
        } catch (MathException) {
            abort(409, trans('erpnext.errors.amount'));
        }
    }

    private static function state(array $remote, int $total): string
    {
        return match ((string) ($remote['docstatus'] ?? '')) {
            '0' => 'draft',
            '1' => match (true) {
                ($outstanding = self::moneyCents($remote['outstanding_amount'] ?? null)) <= 0 => 'paid',
                $outstanding < $total => 'part_paid',
                default => 'open',
            },
            '2' => 'cancelled',
            default => abort(409, trans('erpnext.errors.identity')),
        };
    }
}
