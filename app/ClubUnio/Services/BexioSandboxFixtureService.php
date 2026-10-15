<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BexioSandboxFixtureService
{
    public const REFERENCE = 'clubora-sandbox-fixture:v1:invoice-member';

    public function connectionHash(Tenant $tenant): string
    {
        $hash = $this->optionalConnectionHash($tenant);
        if ($hash === null) {
            throw new \RuntimeException('Independent connection required', 409);
        }

        return $hash;
    }

    public function optionalConnectionHash(Tenant $tenant): ?string
    {
        $connection = app(BexioConnectionService::class)->connection($tenant);
        if (empty($connection['connected_at']) || empty($connection['fingerprint'])) {
            return null;
        }

        // Rotation preserves these values; a new authorization invalidates the binding.
        return hash('sha256', $connection['fingerprint']."\0".$connection['connected_at']);
    }

    private function get(string $token, string $path, array $query = []): array
    {
        $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(25)
            ->withoutRedirecting()->get('https://api.bexio.com'.$path, $query);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException('Sandbox read failed', $response->status());
        }

        return $response->json();
    }

    private function verifyProfile(string $token): void
    {
        $profiles = $this->get($token, '/2.0/company_profile');
        if (count($profiles) !== 1 || ($profiles[0]['name'] ?? '') !== 'SVS Api Test'
            || (int) ($profiles[0]['id'] ?? 0) !== 1) {
            throw new \RuntimeException('Sandbox identity mismatch', 409);
        }
    }

    public function seed(Account $actor, Tenant $tenant): array
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        $hash = $this->connectionHash($tenant);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->verifyProfile($token);
        $contacts = $this->get($token, '/2.0/contact', ['limit' => 500]);
        $invoices = $this->get($token, '/2.0/kb_invoice', ['limit' => 500]);
        if (count($contacts) >= 500 || count($invoices) >= 500) {
            throw new \RuntimeException('Incomplete fixture scan', 409);
        }
        $ids = [];
        foreach (['Member', 'Parent', 'Supplier'] as $kind) {
            $matches = array_values(array_filter($contacts, fn ($row) => ($row['name_1'] ?? '') === 'Clubora Sandbox '.$kind.' v1'
                && ($row['remarks'] ?? '') === 'clubora-sandbox-fixture:v1:'.strtolower($kind)));
            if (count($matches) !== 1 || ! ctype_digit((string) ($matches[0]['id'] ?? '')) || (int) $matches[0]['id'] < 1) {
                throw new \RuntimeException('Missing or ambiguous remote fixture', 409);
            }
            $ids[$kind] = (int) $matches[0]['id'];
        }
        $matches = array_values(array_filter($invoices, fn ($row) => ($row['api_reference'] ?? '') === self::REFERENCE));
        if (count($matches) !== 1 || ! ctype_digit((string) ($matches[0]['id'] ?? '')) || (int) $matches[0]['id'] < 1) {
            throw new \RuntimeException('Missing or ambiguous invoice fixture', 409);
        }
        $remoteId = (int) $matches[0]['id'];
        $remote = $this->get($token, '/2.0/kb_invoice/'.$remoteId);
        if (($remote['api_reference'] ?? '') !== self::REFERENCE || (int) ($remote['contact_id'] ?? 0) !== $ids['Member']
            || (int) ($remote['kb_item_status_id'] ?? 0) !== 7 || (float) ($remote['total'] ?? -1) !== 25.0
            || (float) ($remote['total_taxes'] ?? -1) !== 0.0 || (int) ($remote['mwst_type'] ?? -1) !== 2) {
            throw new \RuntimeException('Remote draft does not match fixture', 409);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $hash, $ids, $remoteId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            if (! hash_equals($hash, $this->connectionHash($tenant))) {
                throw new \RuntimeException('Connection changed', 409);
            }
            $table = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures');
            $existing = (clone $table)->where('tenant_id', $tenant->id)->first();
            if ($existing) {
                if (! hash_equals($hash, $existing->connection_hash) || (int) $existing->remote_invoice_id !== $remoteId
                    || (int) $existing->remote_contact_id !== $ids['Member']) {
                    throw new \RuntimeException('Existing binding differs; reviewed reconciliation required', 409);
                }

                return ['created' => false, 'invoice_id' => $existing->invoice_id];
            }
            $members = [];
            foreach ($ids as $kind => $id) {
                $member = new Member;
                $member->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Clubora Sandbox',
                    'last_name' => $kind.' v1', 'email' => strtolower($kind).'@example.invalid',
                    'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Sissach',
                    'bexio_contact_id' => $id])->save();
                $members[$kind] = $member->id;
            }
            $invoice = new Invoice;
            $invoice->forceFill(['tenant_id' => $tenant->id, 'member_id' => $members['Member'],
                'notes' => 'SANDBOX: synthetische Testrechnung v1 - nicht versenden, keine echte Forderung.',
                'workflow_status_id' => app(InvoiceWorkflowService::class)->status($tenant, 'draft'),
                'bexio_invoice_id' => (string) $remoteId])->save();
            $line = new InvoiceLine;
            $line->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id,
                'description' => 'Synthetischer ClubUnio Testbeitrag - keine echte Forderung',
                'gross_amount' => '25.00', 'discount' => '0.00', 'net_amount' => '25.00', 'contribution' => false])->save();
            $table->insert(['tenant_id' => $tenant->id, 'connection_hash' => $hash,
                'member_id' => $members['Member'], 'parent_id' => $members['Parent'], 'supplier_id' => $members['Supplier'],
                'invoice_id' => $invoice->id, 'remote_invoice_id' => $remoteId, 'remote_contact_id' => $ids['Member']]);
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id,
                'actor_id' => $actor->id, 'object_type' => 'invoices', 'object_id' => $invoice->id,
                'before_value' => '{}', 'after_value' => json_encode(['action' => 'sandbox_fixture_created',
                    'members' => $members, 'remote_invoice_id' => $remoteId], JSON_THROW_ON_ERROR)]);

            return ['created' => true, 'invoice_id' => $invoice->id];
        });
    }

    public function guardPdf(Tenant $tenant, Invoice $invoice, string $token): void
    {
        $binding = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        if (! $binding) {
            return;
        }
        $reference = self::REFERENCE;
        $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
            ->where('tenant_id', $tenant->id)->where('kind', 'invoice')->where('invoice_id', $invoice->id)
            ->where('state', 'accepted')->first();
        if ($dispatch && hash_equals($binding->connection_hash, $dispatch->connection_hash)) {
            $binding->invoice_id = $dispatch->invoice_id;
            $binding->remote_invoice_id = $dispatch->remote_id;
            $reference = 'clubora-sandbox:'.$dispatch->reference;
            $payload = json_decode($dispatch->payload, true, 512, JSON_THROW_ON_ERROR);
            app(BexioSandboxDispatchService::class)->invoiceContactLabel($tenant, (int) $payload['contact_id']);
            $recipient = $dispatch->source_trial_id ? DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('id', $dispatch->source_trial_id)->value('sandbox_recipient_id') : null;
            if ($recipient) {
                app(BexioSandboxRecipientService::class)->resolve($tenant, $invoice, (int) $recipient, (int) $payload['contact_id']);
                $binding->member_id = $invoice->member_id;
            }
            $binding->remote_contact_id = (int) $payload['contact_id'];
        }
        // Once sandbox testing starts, imported remote IDs must never resolve against it.
        if (! hash_equals($binding->connection_hash, $this->connectionHash($tenant))
            || (int) $invoice->id !== (int) $binding->invoice_id || (int) $invoice->tenant_id !== (int) $tenant->id
            || (string) $invoice->bexio_invoice_id !== (string) $binding->remote_invoice_id
            || (int) $invoice->member_id !== (int) $binding->member_id) {
            throw new \RuntimeException('Document not bound to verified sandbox', 409);
        }
        $this->verifyProfile($token);
        $remote = $this->get($token, '/2.0/kb_invoice/'.$binding->remote_invoice_id);
        if (($remote['api_reference'] ?? '') !== $reference || (int) ($remote['contact_id'] ?? 0) !== (int) $binding->remote_contact_id) {
            throw new \RuntimeException('Remote document identity mismatch', 409);
        }
    }
}
