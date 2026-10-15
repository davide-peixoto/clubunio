<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BexioSandboxRecipientService
{
    private function table()
    {
        return DB::connection('clubunio')->table('clubora.bexio_sandbox_recipients');
    }

    public function resolve(Tenant $tenant, Invoice $invoice, ?int $registryId = null, ?int $contactId = null): object
    {
        abort_unless((int) $invoice->tenant_id === (int) $tenant->id && ! $invoice->legacy_id, 409);
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);
        $row = $this->table()->where('tenant_id', $tenant->id)->where('member_id', $invoice->member_id)->where('connection_hash', $hash)
            ->where('state', 'accepted')->when($registryId !== null, fn ($q) => $q->where('id', $registryId))
            ->when($contactId !== null, fn ($q) => $q->where('remote_id', $contactId))->first();
        abort_unless($row && $row->remote_id > 0, 409, 'Bestätigte Sandbox-Empfängerzuordnung fehlt.');

        return $row;
    }

    public function assertContact(object $row, array $contact): void
    {
        abort_unless((int) ($contact['id'] ?? 0) === (int) $row->remote_id
            && ($contact['name_1'] ?? '') === 'ClubUnio Testmitglied '.$row->id
            && ($contact['remarks'] ?? '') === 'clubunio-sandbox-recipient:'.$row->marker
            && empty($contact['mail']) && empty($contact['mail_second']), 409, 'Sandbox-Testkontakt wurde geändert.');
    }

    public function prepare(Account $actor, Tenant $tenant, Invoice $invoice): int
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        abort_unless(DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->where('connection_hash', $hash)->exists(), 409, 'Bestätigte Sandbox-Verbindung fehlt.');
        $client = app(BexioSandboxClient::class);
        $client->verify($token);
        $row = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $invoice, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
            $fresh = Invoice::where('tenant_id', $tenant->id)->where('active', true)->whereNull('legacy_id')->lockForUpdate()->findOrFail($invoice->id);
            abort_unless(! $fresh->bexio_invoice_id && $fresh->workflowStatus?->code === 'released' && ! $fresh->member->archived_at
                && (int) $fresh->member->tenant_id === (int) $tenant->id, 409);
            abort_unless(hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409);
            $existing = $this->table()->where('tenant_id', $tenant->id)->where('member_id', $fresh->member_id)->where('connection_hash', $hash)->first();
            if ($existing) {
                return $existing;
            }
            $id = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $fresh->member_id, 'connection_hash' => $hash,
                'marker' => (string) Str::uuid(), 'created_by' => $actor->id]);

            return $this->table()->find($id);
        });
        if ($row->state === 'accepted') {
            $this->assertContact($row, $client->get($token, '/2.0/contact/'.$row->remote_id));

            return (int) $row->id;
        }
        if ($row->state !== 'ready') {
            abort_if($row->state === 'running' && CarbonImmutable::parse($row->started_at)->isAfter(now()->subMinutes(3)), 409, 'Testkontakt wird bereits angelegt.');
            $contacts = $client->get($token, '/2.0/contact', ['limit' => 500]);
            abort_unless(array_is_list($contacts) && count($contacts) < 500, 409);
            $matches = array_values(array_filter($contacts, fn ($c) => ($c['remarks'] ?? '') === 'clubunio-sandbox-recipient:'.$row->marker));
            abort_unless(count($matches) === 1, 409, 'Testkontakt nicht eindeutig gefunden. Kein erneuter Anlageversuch.');
            $row->remote_id = $matches[0]['id'];
            $this->assertContact($row, $matches[0]);
        } else {
            $claimed = $this->table()->where('id', $row->id)->where('state', 'ready')->update(['state' => 'running', 'started_at' => now()->toIso8601String()]);
            abort_unless($claimed === 1, 409);
            try {
                abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant)
                    && hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 409, 'Verbindung oder Berechtigung wurde geändert.');
                $result = $client->createTestRecipient($token, $row->id, $row->marker);
                $this->table()->where('id', $row->id)->update(['state' => $result['state'] === 'accepted' ? 'uncertain' : $result['state'], 'remote_id' => $result['id']]);
                abort_unless($result['state'] === 'accepted', 409, 'Testkontakt nicht bestätigt. Nur lesender Abgleich erlaubt.');
                $row->remote_id = $result['id'];
                $this->assertContact($row, $client->get($token, '/2.0/contact/'.$row->remote_id));
            } catch (\Throwable $e) {
                $this->table()->where('id', $row->id)->where('state', 'running')->update(['state' => 'uncertain']);
                throw $e;
            }
        }

        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $row, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant) && hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)), 403);
            $this->table()->where('id', $row->id)->update(['state' => 'accepted', 'remote_id' => $row->remote_id, 'updated_at' => now()->toIso8601String()]);
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'bexio_sandbox_recipients', 'object_id' => $row->id, 'after_value' => json_encode(['action' => 'confirmed', 'remote_id' => $row->remote_id], JSON_THROW_ON_ERROR)]);
        });

        return (int) $row->id;
    }
}
