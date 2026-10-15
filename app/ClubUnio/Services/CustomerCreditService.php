<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\CustomerCredit;
use App\ClubUnio\Models\CustomerCreditAllocation;
use App\ClubUnio\Models\FinancialAdjustmentStatus;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerCreditService
{
    private function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['revision' => $message]);
    }

    private function audit(Account $actor, CustomerCredit $credit, string $action, array $extra = []): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $credit->tenant_id, 'actor_id' => $actor->id, 'object_type' => 'customer_credits',
            'object_id' => $credit->id, 'before_value' => '{}',
            'after_value' => json_encode(['action' => $action, 'revision' => $credit->revision] + $extra, JSON_THROW_ON_ERROR),
        ]);
    }

    public function register(Account $actor, Tenant $tenant, int $noteId, array $input): CustomerCredit
    {
        $this->authorize($actor, $tenant);
        $data = Validator::make($input, ['revision' => 'required|integer|min:1',
            'external_reference' => 'required|string|regex:/^GS-[0-9]{1,20}$/iD', 'attestation' => 'accepted'])->validate();
        $note = CreditNote::where('tenant_id', $tenant->id)->findOrFail($noteId);
        $remote = app(CreditNoteService::class)->remote($actor, $tenant, $note->invoice_id);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $noteId, $data, $remote) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            abort_unless(hash_equals($remote['connection_hash'], app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            $note = CreditNote::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($noteId);
            abort_if(CustomerCredit::where('tenant_id', $tenant->id)->where('credit_note_id', $noteId)->exists(), 409, 'Kundenguthaben bereits erfasst.');
            abort_unless((int) $note->revision === (int) $data['revision'] && $note->status->code === 'released', 409);
            abort_unless((string) ($note->source_snapshot['bexio_invoice_id'] ?? '') === (string) $remote['remote_id'], 409);
            abort_unless(! empty($remote['contact_id']) && ! empty($remote['currency_id']), 409, 'Verifizierter Rechnungsempfänger fehlt.');
            $reference = strtoupper($data['external_reference']);
            abort_if(CustomerCredit::where('tenant_id', $tenant->id)->where('connection_hash', $remote['connection_hash'])->where('external_reference', $reference)->exists(), 409, 'Kundenguthaben bereits erfasst.');
            abort_if($note->refunds()->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))->exists(), 409);
            abort_if(CreditNote::where('tenant_id', $tenant->id)->where('connection_hash', $remote['connection_hash'])
                ->whereRaw('upper(external_reference)=?', [$reference])->exists(), 409, 'Gutschrift bereits zugeordnet.');
            $credit = new CustomerCredit;
            $credit->forceFill(['tenant_id' => $tenant->id, 'member_id' => $note->member_id, 'credit_note_id' => $noteId,
                'connection_hash' => $remote['connection_hash'], 'contact_id' => $remote['contact_id'], 'currency_id' => $remote['currency_id'],
                'external_reference' => $reference, 'amount_cents' => $note->amount_cents, 'created_by' => $actor->id])->save();
            // Registration is a manual tax/amount attestation, not provider confirmation or a cash entitlement.
            $note->revision++;
            $note->save();
            $this->audit($actor, $credit, 'manual_registration', ['credit_note_id' => $noteId, 'amount_cents' => $note->amount_cents, 'external_reference' => $reference]);

            return $credit->refresh();
        });
    }

    public function allocate(Account $actor, Tenant $tenant, int $creditId, int $revision, int $invoiceId): CustomerCredit
    {
        $this->authorize($actor, $tenant);
        $credit = CustomerCredit::where('tenant_id', $tenant->id)->findOrFail($creditId);
        abort_if($credit->allocations()->count() >= 50, 409, 'Mehr als 50 Verrechnungen erfordern eine gesonderte Prüfung.');
        $remote = app(CreditNoteService::class)->remote($actor, $tenant, $invoiceId);
        // Re-read all earlier allocations, so removed/changed Bexio entries cannot inflate remaining credit.
        $observations = [];
        foreach ($credit->allocations()->get() as $allocation) {
            $observations[$allocation->invoice_id] = $allocation->invoice_id === $invoiceId ? $remote
                : app(CreditNoteService::class)->remote($actor, $tenant, $allocation->invoice_id);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $creditId, $revision, $invoiceId, $remote, $observations) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $credit = CustomerCredit::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($creditId);
            abort_unless(hash_equals($credit->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            abort_unless((int) $credit->revision === $revision, 409, 'Guthaben wurde geändert. Bitte neu laden.');
            $note = CreditNote::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($credit->credit_note_id);
            abort_unless(in_array($note->status->code, ['released', 'confirmed'], true), 409);
            abort_if($note->refunds()->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))->exists(), 409, 'Rückerstattung bereits vorgesehen.');
            $match = $this->match($credit, $remote);
            abort_if(CustomerCredit::where('tenant_id', $tenant->id)->where('id', '<>', $credit->id)->where('connection_hash', $credit->connection_hash)
                ->where('voucher_id', $match['voucher_id'])->exists(), 409, 'Bexio-Gutschrift gehört bereits zu einem anderen Guthaben.');
            $used = 0;
            foreach ($credit->allocations()->get() as $allocation) {
                $fresh = $observations[$allocation->invoice_id] ?? null;
                abort_unless($fresh, 409);
                $prior = $this->match($credit, $fresh);
                $savedPaymentIds = $allocation->evidence['allocation']['payment_ids'];
                abort_unless((int) $prior['amount_cents'] === (int) $allocation->amount_cents
                    && count($prior['payment_ids']) === count($savedPaymentIds) && !array_diff($prior['payment_ids'], $savedPaymentIds), 409, 'Frühere Bexio-Verrechnung wurde geändert.');
                $used += (int) $allocation->amount_cents;
            }
            abort_if($credit->allocations()->where('invoice_id', $invoiceId)->exists(), 409, 'Rechnung bereits abgeglichen.');
            if ($match['amount_cents'] > (int) $credit->amount_cents - $used) {
                $this->fail('Bexio-Verrechnung übersteigt das verbleibende Kundenguthaben.');
            }
            abort_if(CreditNote::where('tenant_id', $tenant->id)->where('id', '<>', $note->id)->where('connection_hash', $credit->connection_hash)
                ->where(fn ($q) => $q->whereRaw('upper(external_reference)=?', [$credit->external_reference])
                    ->orWhereRaw("external_snapshot->'allocation'->>'voucher_id' = ?", [$match['voucher_id']]))->exists(), 409);
            $allocation = new CustomerCreditAllocation;
            $allocation->forceFill(['tenant_id' => $tenant->id, 'customer_credit_id' => $credit->id, 'invoice_id' => $invoiceId,
                'connection_hash' => $credit->connection_hash, 'voucher_id' => $match['voucher_id'], 'amount_cents' => $match['amount_cents'],
                'evidence' => ['allocation' => $match, 'remote' => $remote], 'created_by' => $actor->id])->save();
            $credit->voucher_id = $match['voucher_id'];
            $credit->revision++;
            $credit->save();
            if ($used + $match['amount_cents'] === (int) $credit->amount_cents) {
                $status = FinancialAdjustmentStatus::where('tenant_id', $tenant->id)->where('code', 'confirmed')->where('active', true)->firstOrFail();
                $note->forceFill(['status_id' => $status->id, 'external_reference' => $credit->external_reference, 'connection_hash' => $credit->connection_hash,
                    'external_snapshot' => ['customer_credit_id' => $credit->id, 'allocation_ids' => $credit->allocations()->pluck('id')->all()],
                    'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'revision' => $note->revision + 1])->save();
            }
            $this->audit($actor, $credit, 'verified_allocation', ['invoice_id' => $invoiceId, 'amount_cents' => $match['amount_cents']]);

            return $credit->refresh();
        });
    }

    private function match(CustomerCredit $credit, array $remote): array
    {
        abort_unless(hash_equals($credit->connection_hash, $remote['connection_hash']) && (string) $credit->contact_id === (string) ($remote['contact_id'] ?? '')
            && (int) $credit->currency_id === (int) ($remote['currency_id'] ?? 0), 409, 'Sandbox, Rechnungsempfänger oder Währung stimmen nicht überein.');
        $matches = array_values(array_filter($remote['credit_allocations'], fn ($row) => $row['reference'] === $credit->external_reference));
        abort_unless(count($matches) === 1 && $matches[0]['amount_cents'] > 0, 409, 'Keine passende Bexio-Verrechnung vorhanden.');
        abort_unless(! $credit->voucher_id || $credit->voucher_id === $matches[0]['voucher_id'], 409, 'Bexio-Gutschrift-ID stimmt nicht überein.');

        return $matches[0];
    }

    public function correctReference(Account $actor, Tenant $tenant, int $id, int $revision, string $reference): CustomerCredit
    {
        $this->authorize($actor, $tenant);
        Validator::make(['reference' => $reference], ['reference' => 'required|regex:/^GS-[0-9]{1,20}$/iD'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $reference) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $credit = CustomerCredit::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            abort_unless((int) $credit->revision === $revision && ! $credit->allocations()->exists(), 409, 'Bereits verrechnete Guthaben sind unveränderlich.');
            $reference = strtoupper($reference);
            abort_if(CustomerCredit::where('tenant_id', $tenant->id)->where('id', '<>', $id)->where('connection_hash', $credit->connection_hash)->where('external_reference', $reference)->exists(), 409);
            abort_if(CreditNote::where('tenant_id', $tenant->id)->where('connection_hash', $credit->connection_hash)->whereRaw('upper(external_reference)=?', [$reference])->exists(), 409);
            $previousReference = $credit->external_reference;
            $credit->external_reference = $reference;
            $credit->revision++;
            $credit->save();
            $this->audit($actor, $credit, 'correct_reference', ['reference_before' => $previousReference, 'external_reference' => $reference]);

            return $credit->refresh();
        });
    }
}
