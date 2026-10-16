<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\InvoiceStatus;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BexioReconciliationService
{
    public function sandbox(Tenant $tenant): bool
    {
        return DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->exists();
    }

    public function enabled(Tenant $tenant): bool
    {
        if ($this->sandbox($tenant)) {
            return true; // Per-document admin/registry checks remain mandatory.
        }
        // ClubUnio settings use the editable value; the legacy import flag is not an activation switch.
        foreach (['BEXIO_ACTIVE', 'BEXIO_AKTIV', 'BEXIO_ENABLED'] as $key) {
            $row = Setting::where('tenant_id', $tenant->id)->where('area', 'bexio')->where('key', $key)->first();
            if ($row) {
                return in_array(mb_strtolower(trim(Crypt::decryptString($row->encrypted_value))), ['1', 'ja', 'yes', 'true', 'on', 'aktiv'], true);
            }
        }

        return false;
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'bexio', 'sync');
        if ($this->sandbox($tenant)) {
            app(BexioSandboxDispatchService::class)->authorize($actor, $tenant);
        }
    }

    public function document(Account $actor, Tenant $tenant, string $type, int $id, bool $lock = false)
    {
        $this->authorize($actor, $tenant);
        app(ModulePermissionService::class)->authorize($actor, $tenant, match ($type) {
            'invoice' => 'invoices', 'expense' => 'expenses', default => abort(403),
        }, 'sync');
        if ($this->sandbox($tenant)) {
            app(BexioSandboxDispatchService::class)->statusDocument($actor, $tenant, $type, $id);
        }
        $class = match ($type) {
            'invoice' => Invoice::class, 'expense' => Expense::class, default => abort(422)
        };

        return $class::where('tenant_id', $tenant->id)->where('active', true)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function preview(Account $actor, Tenant $tenant, string $type, int $id): int
    {
        $this->authorize($actor, $tenant);
        $this->document($actor, $tenant, $type, $id);
        abort_unless(in_array($type, ['invoice', 'expense'], true), 422);

        return app(SyncWorkService::class)->run($actor, $tenant, $type.'_status', $id);
    }

    public function previewNow(Account $actor, Tenant $tenant, string $type, int $id): int
    {
        $document = $this->document($actor, $tenant, $type, $id);
        $remote = trim((string) $document->{$type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id'});
        $reference = (string) Str::uuid();
        $row = ['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $type, 'object_id' => $id,
            'reference' => $reference, 'remote_id' => $remote, 'fingerprint' => $this->fingerprint($document, $type)];
        if (! $this->enabled($tenant)) {
            return DB::connection('clubunio')->table('clubora.bexio_checks')->insertGetId($row + ['state' => 'disabled', 'message' => 'Bexio kein Update - Deaktiviert']);
        }
        try {
            $data = $this->sandbox($tenant)
                ? app(BexioSandboxDispatchService::class)->readStatus($actor, $tenant, $type, $id)
                : app(BexioDocumentClient::class)->read($tenant, $type, $remote);
            // Validate identity here as well, independently of transport implementation.
            if (strtolower((string) ($data['id'] ?? '')) !== strtolower($remote)) {
                throw new \RuntimeException('Unexpected document identity', 502);
            }
            $status = $type === 'invoice' ? ($data['kb_item_status_id'] ?? null) : ($data['status'] ?? null);
            if (! is_scalar($status) || trim((string) $status) === '' || strlen((string) $status) > 50) {
                throw new \RuntimeException('Missing provider status', 502);
            }
            $status = trim((string) $status);
            $financials = $this->financials($type, $document, $data);
            $proposal = $this->proposal($tenant, $type, $document, $status, $data, $financials);
            $row += ['remote_status' => $status, 'financials' => json_encode($financials, JSON_THROW_ON_ERROR), 'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR),
                'state' => $proposal['change'] ? 'ready' : 'unchanged', 'message' => $proposal['label']];
        } catch (\Throwable $error) {
            $row += ['state' => 'failed', 'provider_status' => (int) $error->getCode(),
                'message' => $error instanceof ValidationException ? $error->getMessage() : 'Bexio-Abgleich nicht möglich. Referenz: '.$reference];
            Log::warning('clubunio.bexio.reconciliation_failed', ['reference' => $reference, 'tenant_id' => $tenant->id,
                'type' => $type, 'object_id' => $id, 'status' => (int) $error->getCode()]);
        }

        return DB::connection('clubunio')->table('clubora.bexio_checks')->insertGetId($row);
    }

    private function financials(string $type, $document, array $data): array
    {
        $money = app(BexioBalanceService::class);
        $expected = $type === 'invoice' ? app(InvoiceTaxService::class)->summary($document, true)['total_cents']
            : $money->cents((string) $document->activeLines()->sum('amount'));

        $balance = $money->normalize($type, $data, $expected);
        $tenant = Tenant::findOrFail($document->tenant_id);
        if ($type === 'invoice' && $this->sandbox($tenant)) {
            $balance += app(BexioInvoiceCashService::class)->read($tenant, (string) $data['id'], $balance);
        }

        return $balance;
    }

    private function proposal(Tenant $tenant, string $type, $document, string $status, array $data, ?array $financials = null): array
    {
        $current = DB::connection('clubunio')->table('clubora.bexio_balances')->where('tenant_id', $tenant->id)->where('object_type', $type)->where('object_id', $document->id)->first();
        $balanceChanged = $financials !== null && (! $current || collect($financials)->contains(fn ($value, $key) => (int) $current->$key !== $value));
        if ($type === 'invoice') {
            $mapping = InvoiceStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', $status)->first();
            $lineStatus = $mapping ? InvoiceLineStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', $mapping->position_code)->first() : null;
            if (! $mapping || ! $lineStatus) {
                throw ValidationException::withMessages(['mapping' => 'Für diesen Bexio-Status fehlt eine aktive Rechnungs-/Positionszuordnung.']);
            }

            return ['status_id' => $mapping->id, 'line_status_id' => $lineStatus->id, 'position_code' => $mapping->position_code,
                'label' => $mapping->name, 'change' => $balanceChanged || $document->status_id != $mapping->id
                    || ($status === '19' && ! $document->legacy_id && $document->workflow_status_id && $document->workflowStatus?->code !== 'cancelled')
                    || $document->activeLines()->where(fn ($q) => $q->whereNull('status_id')->orWhere('status_id', '<>', $lineStatus->id))->exists()];
        }
        if ($status !== 'PAID') {
            return ['change' => $balanceChanged, 'status_id' => $document->status_id, 'label' => 'Bexio: '.$status.'; kein Zahlungseingang bestätigt.'];
        }
        if (array_key_exists('pending_amount', $data) && (! is_numeric($data['pending_amount']) || abs((float) $data['pending_amount']) >= 0.005)) {
            throw ValidationException::withMessages(['balance' => 'Bexio meldet bezahlt mit widersprüchlichem Restbetrag.']);
        }
        if ($document->status?->code === 'B') {
            return ['change' => $balanceChanged, 'status_id' => $document->status_id, 'label' => 'Bereits bezahlt'];
        }
        if ($document->status?->code !== 'Z') {
            throw ValidationException::withMessages(['status' => 'Nur zur Zahlung freigegebene Spesen dürfen als bezahlt übernommen werden.']);
        }
        $target = ExpenseStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', 'B')->first();
        if (! $target) {
            throw ValidationException::withMessages(['mapping' => 'Der aktive Spesenstatus Bezahlt fehlt.']);
        }

        return ['status_id' => $target->id, 'label' => $target->name, 'change' => true];
    }

    public function apply(Account $actor, Tenant $tenant, int $checkId): void
    {
        $this->authorize($actor, $tenant);
        if (! $this->enabled($tenant)) {
            throw ValidationException::withMessages(['check' => 'Bexio kein Update - Deaktiviert']);
        }
        $saved = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', $tenant->id)->where('id', $checkId)->first();
        abort_unless($saved && $saved->actor_id === $actor->id, 404);
        if ($saved->state === 'applied') {
            return;
        }
        if ($saved->state !== 'ready' || ! $saved->financials || CarbonImmutable::parse($saved->created_at)->lt(now()->subMinutes(10))) {
            throw ValidationException::withMessages(['check' => 'Diese Vorschau ist nicht mehr gültig. Bitte erneut aus Bexio laden.']);
        }
        $document = $this->document($actor, $tenant, $saved->object_type, $saved->object_id);
        $provider = $this->sandbox($tenant)
            ? app(BexioSandboxDispatchService::class)->readStatus($actor, $tenant, $saved->object_type, $saved->object_id)
            : app(BexioDocumentClient::class)->read($tenant, $saved->object_type, $saved->remote_id);
        $providerStatus = (string) ($provider[$saved->object_type === 'invoice' ? 'kb_item_status_id' : 'status'] ?? '');
        $financials = $this->financials($saved->object_type, $document, $provider);
        $savedFinancials = json_decode($saved->financials, true, 32, JSON_THROW_ON_ERROR);
        ksort($financials);
        ksort($savedFinancials);
        if ((string) ($provider['id'] ?? '') !== $saved->remote_id || $providerStatus !== $saved->remote_status
            || $financials !== $savedFinancials) {
            throw ValidationException::withMessages(['check' => 'Bexio wurde inzwischen geändert. Bitte erneut prüfen.']);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $checkId, $financials, $provider) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $check = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', $tenant->id)->where('id', $checkId)->lockForUpdate()->first();
            abort_unless($check && $check->actor_id === $actor->id, 404);
            if ($check->state === 'applied') {
                return;
            }
            if ($check->state !== 'ready' || CarbonImmutable::parse($check->created_at)->lt(now()->subMinutes(10))) {
                throw ValidationException::withMessages(['check' => 'Diese Vorschau ist nicht mehr gültig. Bitte erneut aus Bexio laden.']);
            }
            $document = $this->document($actor, $tenant, $check->object_type, $check->object_id, true);
            if (! hash_equals($check->fingerprint, $this->fingerprint($document, $check->object_type))) {
                throw ValidationException::withMessages(['check' => 'Der Datensatz wurde inzwischen geändert. Bitte erneut prüfen.']);
            }
            $proposal = json_decode($check->proposal, true, 32, JSON_THROW_ON_ERROR);
            // Revalidate lookups before committing; configuration may have changed since preview.
            $fresh = $this->proposal($tenant, $check->object_type, $document, $check->remote_status, $provider, $financials);
            ksort($fresh);
            ksort($proposal);
            if ($fresh !== $proposal) {
                throw ValidationException::withMessages(['check' => 'Die Statuszuordnung wurde geändert. Bitte erneut prüfen.']);
            }
            $before = $document->only($check->object_type === 'invoice' ? ['status_id', 'revision', 'workflow_status_id'] : ['status_id', 'revision']);
            $previousPosition = $check->object_type === 'invoice' ? $document->status?->position_code : null;
            $document->status_id = $proposal['status_id'];
            $document->revision++;
            if ($check->object_type === 'invoice') {
                $document->bexio_updated_at = now('Europe/Zurich')->format('Y-m-d H:i:s');
                $document->activeLines()->update(['status_id' => $proposal['line_status_id']]);
                if ($check->remote_status === '19' && ! $document->legacy_id && $document->workflow_status_id) {
                    $document->workflow_status_id = app(InvoiceWorkflowService::class)->status($tenant, 'cancelled');
                }
            } else {
                if ($check->remote_status === 'PAID' && $before['status_id'] != $document->status_id) {
                    $document->paid_on = now('Europe/Zurich')->toDateString();
                    $document->paid_by = 'Bexio-Abgleich';
                }
            }
            $document->save();
            DB::connection('clubunio')->table('clubora.bexio_balances')->updateOrInsert(['tenant_id' => $tenant->id, 'object_type' => $check->object_type, 'object_id' => $document->id],
                $financials + ['remote_id' => $check->remote_id, 'remote_status' => $check->remote_status, 'observed_at' => now()->toIso8601String()]);
            if ($check->object_type === 'expense' && $before['status_id'] != $document->status_id) {
                app(ExpenseMessageService::class)->record($tenant, $document->fresh(['status', 'member', 'season', 'round', 'teamSeason.team']));
            } elseif ($check->object_type === 'invoice' && $before['status_id'] != $document->status_id && $proposal['position_code'] !== 'P') {
                $this->invoiceNotification($tenant, $document, $proposal['label'], $proposal['position_code'] === 'O' && in_array($previousPosition, [null, 'P'], true));
            }
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => $check->object_type === 'invoice' ? 'invoices' : 'expenses', 'object_id' => $document->id,
                'before_value' => json_encode($before), 'after_value' => json_encode($document->only(array_keys($before)) + ['action' => 'bexio_reconcile', 'reference' => $check->reference, 'financials' => $financials])]);
            DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $checkId)->update(['state' => 'applied', 'applied_at' => now()->toIso8601String()]);
        });
    }

    private function fingerprint($document, string $type): string
    {
        $data = $document->only(['id', 'tenant_id', 'status_id', 'revision', 'active', 'member_id', 'bexio_invoice_id', 'bexio_bill_id', 'tax_mode', 'rounding_cents', 'cash_rounding']);
        $data['lines'] = $document->activeLines()->orderBy('id')->get()->map(fn ($line) => $line->only(['id', 'status_id', 'revision', 'net_amount', 'amount', 'tax_snapshot']))->all();

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function invoiceNotification(Tenant $tenant, Invoice $invoice, string $status, bool $new): void
    {
        $db = DB::connection('clubunio');
        $push = app(MessageTemplateService::class)->render($tenant, $new ? 'invoice.open' : 'invoice.status', 'push', ['verein' => $tenant->name,
            'mitglied' => $invoice->member?->display_name ?? '', 'saison' => $invoice->season?->name ?? '',
            'betrag' => 'CHF '.number_format(app(InvoiceTaxService::class)->summary($invoice)['total_cents'] / 100, 2, '.', "'"), 'status' => $status]);
        if (! $push) {
            return;
        }
        $accounts = $db->table('clubora.account_member_links as l')->join('clubora.tenant_accounts as ta', fn ($j) => $j->on('ta.account_id', '=', 'l.account_id')->on('ta.tenant_id', '=', 'l.tenant_id'))
            ->join('clubora.accounts as a', 'a.id', '=', 'l.account_id')->where('l.tenant_id', $tenant->id)->where('l.member_id', $invoice->member_id)
            ->where('l.state', 'approved')->where('l.kind', 'self')->where('ta.portal_enabled', true)->where('a.active', true)->distinct()->pluck('l.account_id');
        foreach ($accounts as $account) {
            $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account,
                'title' => $push['title'], 'body' => $push['body'], 'object_type' => 'invoice', 'object_id' => $invoice->id]);
        }
    }
}
