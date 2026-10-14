<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\FinancialAdjustmentStatus;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Refund;
use App\ClubUnio\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditNoteService
{
    private function capacities($sources, array $summary): array
    {
        $capacities = [];
        $used = 0;
        $money = app(BexioBalanceService::class);
        foreach ($sources as $line) {
            $value = $money->cents($line->net_amount);
            $capacity = BigDecimal::of($value)->multipliedBy($summary['total_cents'])->dividedBy($summary['unrounded_cents'], 0, RoundingMode::DOWN)->toInt();
            $capacities[$line->id] = $capacity;
            $used += $capacity;
        }
        foreach ($sources as $line) {
            if ($used >= $summary['total_cents']) {
                break;
            }
            if ($money->cents($line->net_amount) > 0) {
                $capacities[$line->id]++;
                $used++;
            }
        }

        return $capacities;
    }

    public function availableLines(Account $actor, Tenant $tenant, int $invoiceId): array
    {
        $this->assertActor($actor, $tenant);
        $invoice = Invoice::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($invoiceId);
        $summary = app(InvoiceTaxService::class)->summary($invoice, true);
        if ($summary['unrounded_cents'] <= 0) {
            return [];
        }
        $sources = $invoice->activeLines()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $capacities = $this->capacities($sources, $summary);
        $used = DB::connection('clubunio')->table('clubora.credit_note_lines as l')->join('clubora.credit_notes as n', 'n.id', '=', 'l.credit_note_id')
            ->join('clubora.financial_adjustment_statuses as s', 's.id', '=', 'n.status_id')->where('n.tenant_id', $tenant->id)
            ->where('n.invoice_id', $invoiceId)->where('s.code', '<>', 'cancelled')->groupBy('l.invoice_line_id')->selectRaw('l.invoice_line_id,SUM(l.amount_cents) as amount')->pluck('amount', 'invoice_line_id');

        return $sources->map(fn ($line) => ['invoice_line_id' => $line->id, 'amount_cents' => max(0, $capacities[$line->id] - (int) ($used[$line->id] ?? 0))])
            ->filter(fn ($row) => $row['amount_cents'] > 0)->map(fn ($row) => ['invoice_line_id' => $row['invoice_line_id'], 'amount' => app(InvoiceWorkflowService::class)->decimal($row['amount_cents'])])->values()->all();
    }

    private function assertActor(Account $actor, Tenant $tenant): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
    }

    private function status(Tenant $tenant, string $code): int
    {
        $id = FinancialAdjustmentStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', $code)->value('id');
        if (! $id) {
            $this->fail('Status fehlt oder ist inaktiv: '.$code);
        }

        return $id;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['revision' => $message]);
    }

    private function invoice(Tenant $tenant, int $id): Invoice
    {
        return Invoice::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id);
    }

    private function audit(Account $actor, Tenant $tenant, string $type, $record, string $operation, array $before = []): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => $type, 'object_id' => $record->id, 'before_value' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_value' => json_encode(['action' => $operation, 'revision' => $record->revision, 'status_id' => $record->status_id,
                'amount_cents' => $record->amount_cents], JSON_THROW_ON_ERROR)]);
    }

    public function create(Account $actor, Tenant $tenant, int $invoiceId, array $input): CreditNote
    {
        $this->assertActor($actor, $tenant);
        $data = Validator::make($input, ['request_id' => 'required|uuid', 'revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:1000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.invoice_line_id' => 'required|integer|min:1|distinct',
            'lines.*.amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2'])->validate();
        $hash = hash('sha256', json_encode([$invoiceId, $data], JSON_THROW_ON_ERROR));

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $invoiceId, $data, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $tenant);
            $existing = CreditNote::where('tenant_id', $tenant->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409);

                return $existing;
            }
            $invoice = $this->invoice($tenant, $invoiceId);
            if ((int) $invoice->revision !== (int) $data['revision']) {
                $this->fail('Rechnung wurde geändert. Bitte neu laden.');
            }
            // Imported amounts without a reviewed tax snapshot cannot become new accounting corrections.
            abort_unless(! $invoice->legacy_id && in_array($invoice->workflowStatus?->code, ['released', 'cancelled'], true), 409, 'Eigene freigegebene Rechnung erforderlich.');
            $summary = app(InvoiceTaxService::class)->summary($invoice, true);
            $sources = $invoice->activeLines()->where('tenant_id', $tenant->id)->orderBy('id')->get();
            $raw = $summary['unrounded_cents'];
            abort_unless($raw > 0 && $summary['total_cents'] > 0, 422);
            $money = app(BexioBalanceService::class);
            // Allocate the original rounded total across its lines; never round each correction anew.
            $capacities = $this->capacities($sources, $summary);
            $taxCaps = [];
            $assignedTax = BigDecimal::zero();
            foreach ($sources as $index => $line) {
                $basis = $line->tax_snapshot['rate_basis_points'] ?? 0;
                $taxCaps[$line->id] = $invoice->tax_mode === 'inclusive'
                    ? BigDecimal::of($money->cents($line->net_amount))->dividedBy(100, 2)->multipliedBy($basis)->dividedBy(10000 + $basis, 6, RoundingMode::HALF_UP)
                        ->multipliedBy($summary['total_cents'])->dividedBy($raw, 6, RoundingMode::DOWN) : BigDecimal::zero();
                $assignedTax = $assignedTax->plus($taxCaps[$line->id]);
            }
            $taxable = $sources->last(fn ($line) => ($line->tax_snapshot['rate_basis_points'] ?? 0) > 0 && $money->cents($line->net_amount) > 0);
            if ($taxable) {
                $taxCaps[$taxable->id] = $taxCaps[$taxable->id]->plus(BigDecimal::of($summary['tax'])->minus($assignedTax));
            }
            $rows = [];
            $total = 0;
            $tax = BigDecimal::zero();
            foreach ($data['lines'] as $entry) {
                $line = $sources->firstWhere('id', (int) $entry['invoice_line_id']);
                abort_unless($line, 404);
                $amount = $money->cents((string) $entry['amount']);
                $prior = DB::connection('clubunio')->table('clubora.credit_note_lines as l')
                    ->join('clubora.credit_notes as n', 'n.id', '=', 'l.credit_note_id')->join('clubora.financial_adjustment_statuses as s', 's.id', '=', 'n.status_id')
                    ->where('n.tenant_id', $tenant->id)->where('n.invoice_id', $invoiceId)->where('l.invoice_line_id', $line->id)->where('s.code', '<>', 'cancelled')
                    ->selectRaw('COALESCE(SUM(l.amount_cents),0) AS amount, COALESCE(SUM(l.included_tax),0) AS tax')->first();
                $capacity = $capacities[$line->id];
                if ($amount > $capacity - (int) $prior->amount) {
                    $this->fail('Gutschrift übersteigt den noch verfügbaren Positionsbetrag.');
                }
                $originalTax = $taxCaps[$line->id];
                $lineTax = $amount === $capacity - (int) $prior->amount ? $originalTax->minus($prior->tax)
                    : $originalTax->multipliedBy($amount)->dividedBy($capacity, 6, RoundingMode::DOWN);
                $rows[] = ['tenant_id' => $tenant->id, 'invoice_line_id' => $line->id, 'description' => $line->description,
                    'amount_cents' => $amount, 'included_tax' => (string) $lineTax, 'tax_snapshot' => json_encode($line->tax_snapshot, JSON_THROW_ON_ERROR)];
                $total += $amount;
                $tax = $tax->plus($lineTax);
            }
            $note = new CreditNote;
            $note->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $invoiceId, 'member_id' => $invoice->member_id, 'status_id' => $this->status($tenant, 'draft'),
                'request_id' => $data['request_id'], 'request_hash' => $hash, 'reason' => $data['reason'], 'amount_cents' => $total, 'included_tax' => (string) $tax,
                'source_snapshot' => ['revision' => $invoice->revision, 'summary' => $summary, 'tax_mode' => $invoice->tax_mode, 'bexio_invoice_id' => $invoice->bexio_invoice_id],
                'created_by' => $actor->id])->save();
            foreach ($rows as $row) {
                DB::connection('clubunio')->table('clubora.credit_note_lines')->insert($row + ['credit_note_id' => $note->id]);
            }
            $this->audit($actor, $tenant, 'credit_notes', $note, 'create');

            return $note->refresh();
        });
    }

    public function change(Account $actor, Tenant $tenant, int $id, int $revision, string $operation, array $input = []): CreditNote
    {
        $this->assertActor($actor, $tenant);
        abort_unless(in_array($operation, ['release', 'cancel', 'confirm'], true), 422);
        $remote = null;
        if ($operation === 'confirm') {
            Validator::make($input, ['external_reference' => 'required|string|max:120|regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}$/', 'attestation' => 'accepted'])->validate();
            $note = CreditNote::where('tenant_id', $tenant->id)->findOrFail($id);
            $remote = $this->remote($actor, $tenant, $note->invoice_id);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $operation, $input, $remote) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $tenant);
            $note = CreditNote::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ((int) $note->revision !== $revision) {
                $this->fail('Gutschrift wurde geändert. Bitte neu laden.');
            }
            $before = $note->only(['status_id', 'revision']);
            $code = $note->status->code;
            if ($operation === 'release') {
                abort_unless($code === 'draft', 409);
                $invoice = $this->invoice($tenant, $note->invoice_id);
                if ((int) $invoice->revision !== (int) $note->source_snapshot['revision']) {
                    $this->fail('Rechnung wurde seit der Erfassung geändert. Entwurf stornieren und neu erfassen.');
                }
                $note->forceFill(['status_id' => $this->status($tenant, 'released'), 'released_by' => $actor->id, 'released_at' => now()]);
            } elseif ($operation === 'cancel') {
                abort_unless(in_array($code, ['draft', 'released'], true), 409, 'Bestätigte Gutschriften bleiben erhalten.');
                $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
                $note->forceFill(['status_id' => $this->status($tenant, 'cancelled'), 'cancellation_reason' => $data['reason']]);
            } else {
                abort_unless($code === 'released', 409);
                abort_unless(hash_equals($remote['connection_hash'], app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
                $allocated = CreditNote::where('tenant_id', $tenant->id)->where('invoice_id', $note->invoice_id)->whereHas('status', fn ($q) => $q->where('code', 'confirmed'))->sum('amount_cents');
                if ($remote['balance']['credit_cents'] < (int) $allocated + (int) $note->amount_cents) {
                    $this->fail('Bexio bestätigt noch keine ausreichende Gutschrift.');
                }
                abort_if(CreditNote::where('tenant_id', $tenant->id)->where('connection_hash', $remote['connection_hash'])->where('external_reference', $input['external_reference'])->exists(), 409, 'Bexio-Referenz ist bereits zugeordnet.');
                $note->forceFill(['status_id' => $this->status($tenant, 'confirmed'), 'external_reference' => $input['external_reference'],
                    'connection_hash' => $remote['connection_hash'], 'external_snapshot' => $remote, 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            }
            $note->revision++;
            $note->save();
            $this->audit($actor, $tenant, 'credit_notes', $note, $operation, $before);

            return $note->refresh();
        });
    }

    public function remote(Account $actor, Tenant $tenant, int $invoiceId): array
    {
        $this->assertActor($actor, $tenant);
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        try {
            return $this->readRemote($actor, $tenant, $invoiceId);
        } catch (ValidationException|HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            Log::warning('clubunio.credit.read_failed', ['reference' => $reference, 'tenant_id' => $tenant->id,
                'invoice_id' => $invoiceId, 'actor_id' => $actor->id, 'provider_status' => (int) $error->getCode()]);
            $this->fail('Bexio-Abgleich nicht verfügbar. Bitte später erneut versuchen. Referenz: '.$reference);
        }
    }

    private function readRemote(Account $actor, Tenant $tenant, int $invoiceId): array
    {
        $service = app(BexioSandboxDispatchService::class);
        $row = $service->statusDocument($actor, $tenant, 'invoice', $invoiceId);
        $data = $service->readStatus($actor, $tenant, 'invoice', $invoiceId);
        $invoice = Invoice::where('tenant_id', $tenant->id)->findOrFail($invoiceId);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        app(InvoiceTaxService::class)->assertRemote($invoice, $payload, $data);
        abort_unless((int) ($data['currency_id'] ?? 0) === (int) $payload['currency_id'], 409);

        $balance = app(BexioBalanceService::class)->normalize('invoice', $data, app(InvoiceTaxService::class)->summary($invoice, true)['total_cents']);
        $balance += app(BexioInvoiceCashService::class)->read($tenant, $row->remote_id, $balance);

        return ['remote_id' => $row->remote_id, 'connection_hash' => $row->connection_hash, 'observed_at' => now()->toIso8601String(), 'balance' => $balance];
    }

    public function createRefund(Account $actor, Tenant $tenant, int $invoiceId, array $input): Refund
    {
        $this->assertActor($actor, $tenant);
        $data = Validator::make($input, ['request_id' => 'required|uuid', 'credit_note_id' => 'nullable|integer|min:1', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2',
            'reason' => 'required|string|min:3|max:1000'])->validate();
        $hash = hash('sha256', json_encode([$invoiceId, $data], JSON_THROW_ON_ERROR));
        $existing = Refund::where('tenant_id', $tenant->id)->where('request_id', $data['request_id'])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409);

            return $existing;
        }
        $remote = $this->remote($actor, $tenant, $invoiceId);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $invoiceId, $data, $hash, $remote) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $tenant);
            $this->invoice($tenant, $invoiceId);
            $existing = Refund::where('tenant_id', $tenant->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409);

                return $existing;
            }
            abort_unless(hash_equals($remote['connection_hash'], app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            $amount = app(BexioBalanceService::class)->cents((string) $data['amount']);
            $reserved = Refund::where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))->sum('amount_cents');
            if ($amount > $remote['balance']['cash_excess_cents'] - (int) $reserved) {
                $this->fail('Kein ausreichendes bestätigtes Guthaben für die Rückerstattung.');
            }
            if (! empty($data['credit_note_id'])) {
                $note = CreditNote::where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->whereHas('status', fn ($q) => $q->where('code', 'confirmed'))->findOrFail($data['credit_note_id']);
                $used = $note->refunds()->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))->sum('amount_cents');
                if ($amount > (int) $note->amount_cents - (int) $used) {
                    $this->fail('Gutschrift ist bereits zur Rückerstattung vorgesehen.');
                }
            }
            $refund = new Refund;
            $refund->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $invoiceId, 'credit_note_id' => $data['credit_note_id'] ?? null, 'status_id' => $this->status($tenant, 'draft'),
                'request_id' => $data['request_id'], 'request_hash' => $hash, 'reason' => $data['reason'], 'amount_cents' => $amount, 'entitlement_snapshot' => $remote, 'created_by' => $actor->id])->save();
            $this->audit($actor, $tenant, 'refunds', $refund, 'create');

            return $refund->refresh();
        });
    }

    public function changeRefund(Account $actor, Tenant $tenant, int $id, int $revision, string $operation, array $input): Refund
    {
        $this->assertActor($actor, $tenant);
        abort_unless(in_array($operation, ['confirm', 'cancel'], true), 422);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $operation, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->assertActor($actor, $tenant);
            $refund = Refund::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ((int) $refund->revision !== $revision) {
                $this->fail('Rückerstattung wurde geändert. Bitte neu laden.');
            }
            abort_unless($refund->status->code === 'draft', 409);
            $before = $refund->only(['status_id', 'revision']);
            if ($operation === 'cancel') {
                $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
                $refund->forceFill(['status_id' => $this->status($tenant, 'cancelled'), 'cancellation_reason' => $data['reason']]);
            } else {
                $data = Validator::make($input, ['paid_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Europe/Zurich')->toDateString(),
                    'proof_reference' => 'required|string|max:120|regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,119}$/', 'attestation' => 'accepted'])->validate();
                abort_if(Refund::where('tenant_id', $tenant->id)->where('proof_reference', $data['proof_reference'])->exists(), 409, 'Nachweis ist bereits erfasst.');
                $refund->forceFill(['status_id' => $this->status($tenant, 'confirmed'), 'paid_on' => $data['paid_on'], 'proof_reference' => $data['proof_reference'], 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            }
            $refund->revision++;
            $refund->save();
            $this->audit($actor, $tenant, 'refunds', $refund, $operation, $before);

            return $refund->refresh();
        });
    }
}
