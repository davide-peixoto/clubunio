<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SponsorRunBillingService
{
    public const FIELDS = ['user_id' => 'Benutzer', 'language_id' => 'Sprache', 'bank_account_id' => 'Bankkonto',
        'currency_id' => 'Währung', 'payment_type_id' => 'Zahlungsart', 'unit_id' => 'Einheit',
        'tax_id' => 'Steuer', 'account_id' => 'Ertragskonto', 'mwst_type' => 'MWST-Typ', 'due_days' => 'Zahlungsziel Tage'];

    public const STATES = ['prepared' => 'Vorbereitet', 'contact_ready' => 'Kontakt zugeordnet',
        'contact_uncertain' => 'Kontakt prüfen', 'draft_uncertain' => 'Erstellung prüfen', 'draft' => 'Entwurf',
        'issue_uncertain' => 'Freigabe prüfen', 'issued' => 'Offen', 'send_uncertain' => 'Versand prüfen',
        'sent' => 'Versendet', 'cancel_uncertain' => 'Storno prüfen', 'cancelled' => 'Storniert',
        'partial' => 'Teilbezahlt', 'paid' => 'Bezahlt', 'running' => 'In Bearbeitung'];

    public function table(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.sponsor_run_invoices')->where('tenant_id', $tenant->id);
    }

    public function authorize(Account $actor, Tenant $tenant, string $function = 'view'): void
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'sponsor_runs', $function);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'bexio', 'view');
    }

    public function configuration(Tenant $tenant): array
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('area', 'sponsor_run')->where('key', 'BEXIO_BILLING')->first();

        return $row ? json_decode(Crypt::decryptString($row->encrypted_value), true, 64, JSON_THROW_ON_ERROR)
            : ['enabled' => false, ...array_fill_keys(array_keys(self::FIELDS), null)];
    }

    public function saveConfiguration(Account $actor, Tenant $tenant, array $input): void
    {
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $this->authorize($actor, $tenant, 'approve');
        $rules = ['enabled' => 'required|boolean'];
        foreach (self::FIELDS as $key => $label) {
            $rules[$key] = ['required_if:enabled,true', 'nullable', 'integer', 'min:'.(in_array($key, ['mwst_type', 'due_days']) ? 0 : 1),
                'max:'.match ($key) {
                    'mwst_type' => 2, 'due_days' => 365, default => 2147483647
                }];
        }
        abort_if(array_diff(array_keys($input), array_keys($rules)), 422);
        $data = Validator::make($input, $rules)->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
            $setting = Setting::where('tenant_id', $tenant->id)->where('area', 'sponsor_run')->where('key', 'BEXIO_BILLING')->first() ?? new Setting;
            $setting->forceFill(['tenant_id' => $tenant->id, 'area' => 'sponsor_run', 'key' => 'BEXIO_BILLING',
                'label' => 'Sponsorenlauf · Bexio-Abrechnung', 'value_type' => 'string', 'enabled' => true,
                'encrypted_value' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'is_secret' => false])->save();
            DB::connection('clubunio')->table('clubora.setting_changes')->insert(['tenant_id' => $tenant->id, 'setting_id' => $setting->id, 'actor_id' => $actor->id]);
        });
    }

    public function catalogs(Account $actor, Tenant $tenant): array
    {
        $this->authorize($actor, $tenant);
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(SponsorRunBexioClient::class);
        $client->verify($token);
        $out = [];
        foreach (SponsorRunBexioClient::CATALOGS as $key => $path) {
            $out[$key] = $client->catalog($token, $key);
        }

        return $out;
    }

    private function binding(Tenant $tenant): string
    {
        return app(BexioSandboxFixtureService::class)->connectionHash($tenant);
    }

    private function fingerprint(Tenant $tenant): string
    {
        return hash('sha256', json_encode($this->configuration($tenant), JSON_THROW_ON_ERROR));
    }

    public function get(Account $actor, Tenant $tenant, int $id): object
    {
        $this->authorize($actor, $tenant);
        $row = $this->table($tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        $row->snapshot = json_decode(Crypt::decryptString($row->snapshot_encrypted), true, 64, JSON_THROW_ON_ERROR);
        unset($row->snapshot_encrypted);

        return $row;
    }

    public function prepare(Account $actor, Tenant $tenant, int $pledge): int
    {
        $this->authorize($actor, $tenant, 'approve');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $pledge) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant, 'approve');
            $existing = $this->table($tenant)->where('pledge_id', $pledge)->first();
            if ($existing) {
                return $existing->id;
            }
            $source = app(SponsorRunService::class)->billingSource($actor, $tenant, $pledge);
            abort_unless($this->configuration($tenant)['enabled'] && $source['total_cents'] > 0, 409,
                'Abrechnung aktivieren und Runden freigeben; Nullbeträge werden nicht fakturiert.');
            $id = $this->table($tenant)->insertGetId(['tenant_id' => $tenant->id, 'pledge_id' => $pledge, 'event_id' => $source['event_id'],
                'reference' => (string) Str::uuid(), 'connection_hash' => $this->binding($tenant), 'configuration_hash' => $this->fingerprint($tenant),
                'snapshot_encrypted' => Crypt::encryptString(json_encode($source + ['invoice_date' => now('Europe/Zurich')->toDateString()], JSON_THROW_ON_ERROR)),
                'total_cents' => $source['total_cents'], 'created_by' => $actor->id]);
            $this->audit($actor, $tenant, $id, 'prepare', 'prepared');

            return $id;
        });
    }

    public function execute(Account $actor, Tenant $tenant, int $id, int $revision, string $action, array $input = []): object
    {
        $this->authorize($actor, $tenant, $this->function($action));
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        abort_unless(in_array($action, ['link-contact', 'create-contact', 'create-draft', 'issue', 'send', 'cancel', 'sync', 'confirm-sent', 'refresh-preview'], true), 422);

        return app(SyncWorkService::class)->run($actor, $tenant, 'sponsor_run_billing', $id, compact('revision', 'action', 'input'));
    }

    public function executeNow(Account $actor, Tenant $tenant, int $id, int $revision, string $action, array $input): object
    {
        $this->authorize($actor, $tenant, $this->function($action));
        abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
        $row = $this->get($actor, $tenant, $id);
        abort_unless(hash_equals($row->connection_hash, $this->binding($tenant)), 409, 'Bexio-Verbindung wurde geändert.');
        if (! in_array($action, ['sync', 'refresh-preview'], true)) {
            abort_unless(hash_equals($row->configuration_hash, $this->fingerprint($tenant)) && $this->configuration($tenant)['enabled'], 409, 'Abrechnungskonfiguration wurde geändert.');
        }
        $lease = (string) Str::uuid();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $action, $lease, $row) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant, $this->function($action));
            abort_unless(BexioConnectionService::canManage($actor, $tenant), 403);
            $current = $this->table($tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($current && (int) $current->revision === $revision, 409, 'Rechnung wurde geändert.');
            abort_unless(! $current->operation_token || ($action === 'sync' && now()->greaterThan($current->lease_until)), 409, 'Laufende Aktion zuerst abwarten oder Ergebnis prüfen.');
            if ($current->operation_token && $action === 'sync') {
                // A crashed writer has no catch/finally result. Preserve that uncertainty
                // before the read so even a failed reconciliation cannot reopen writes.
                $current->state = match ($current->last_operation) {
                    'create-contact' => 'contact_uncertain', 'create-draft' => 'draft_uncertain',
                    'issue' => 'issue_uncertain', 'send' => 'send_uncertain', 'cancel' => 'cancel_uncertain',
                    default => $current->state,
                };
                $row->state = $current->state;
            }
            $allowed = match ($action) {
                'link-contact', 'create-contact' => ['prepared'], 'create-draft' => ['contact_ready'],
                'issue' => ['draft'], 'send' => ['issued'], 'cancel' => ['issued', 'sent'],
                'confirm-sent' => ['send_uncertain'], 'sync' => array_keys(self::STATES), default => [],
                'refresh-preview' => ['prepared', 'contact_ready'],
            };
            abort_unless(in_array($current->state, $allowed, true) || ($action === 'sync' && $current->operation_token), 409, 'Aktion passt nicht zum Rechnungsstatus.');
            $this->table($tenant)->where('id', $id)->update(['state' => $current->state, 'operation_token' => $lease, 'lease_until' => now()->addMinutes(5)->toIso8601String(),
                'last_operation' => $action, 'revision' => $revision + 1, 'updated_at' => now()->toIso8601String()]);
            $this->audit($actor, $tenant, $id, $action.'_started', $current->state);
        });
        $writeAttempted = false;
        try {
            $token = app(BexioConnectionService::class)->accessToken($tenant);
            $client = app(SponsorRunBexioClient::class);
            $client->verify($token);
            $changes = $this->perform($actor, $tenant, $row, $action, $input, $token, $client, $writeAttempted);
        } catch (\Throwable $error) {
            $state = ! $writeAttempted ? $row->state : match ($action) {
                'create-contact' => 'contact_uncertain', 'create-draft' => 'draft_uncertain', 'issue' => 'issue_uncertain',
                'send' => 'send_uncertain', 'cancel' => 'cancel_uncertain', default => $row->state,
            };
            $this->finish($actor, $tenant, $id, $lease, $action.'_failed', ['state' => $state]);
            throw $error;
        }
        $this->finish($actor, $tenant, $id, $lease, $action, $changes, $action === 'confirm-sent' ? $input['reason'] : null);

        return $this->get($actor, $tenant, $id);
    }

    private function finish(Account $actor, Tenant $tenant, int $id, string $lease, string $action, array $changes, ?string $note = null): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $lease, $action, $changes, $note) {
            $updated = $this->table($tenant)->where('id', $id)->where('operation_token', $lease)->update($changes +
                ['operation_token' => null, 'lease_until' => null, 'revision' => DB::connection('clubunio')->raw('revision + 1'), 'updated_at' => now()->toIso8601String()]);
            abort_unless($updated === 1, 409, 'Ergebnis konnte nicht übernommen werden. Bitte Abgleich öffnen.');
            $this->audit($actor, $tenant, $id, $action, $changes['state'], $note);
        });
    }

    private function checkedConfiguration(Tenant $tenant, string $token, SponsorRunBexioClient $client): array
    {
        $c = $this->configuration($tenant);
        $mapping = ['user_id' => 'users', 'language_id' => 'languages', 'bank_account_id' => 'banks',
            'currency_id' => 'currencies', 'payment_type_id' => 'payment_types', 'unit_id' => 'units', 'tax_id' => 'taxes', 'account_id' => 'accounts'];
        foreach ($mapping as $key => $kind) {
            $found = array_values(array_filter($client->catalog($token, $kind), fn ($r) => (int) ($r['id'] ?? 0) === (int) ($c[$key] ?? 0)));
            abort_unless(count($found) === 1 && ($found[0]['is_active'] ?? true) && ! ($found[0]['is_locked'] ?? false), 409, 'Bexio-Katalogzuordnung ungültig: '.$key);
            if ($kind === 'currencies') {
                abort_unless(strtoupper($found[0]['name'] ?? $found[0]['code'] ?? '') === 'CHF', 409, 'Nur CHF wird unterstützt.');
            }
        }

        return $c;
    }

    private function recipientCountry(string $token, SponsorRunBexioClient $client, string $iso): int
    {
        $found = array_values(array_filter($client->catalog($token, 'countries'), fn ($r) => strtoupper($r['iso3166_alpha2'] ?? '') === strtoupper($iso)));
        abort_unless(count($found) === 1, 409, 'Land im Bexio-Katalog nicht eindeutig.');

        return (int) $found[0]['id'];
    }

    private function contactPayload(array $s, array $c, int $country, string $reference): array
    {
        $company = $s['type'] === 'company';

        return ['contact_type_id' => $company ? 1 : 2, 'name_1' => $company ? $s['company'] : $s['last_name'],
            'name_2' => $company ? trim($s['first_name'].' '.$s['last_name']) : $s['first_name'],
            'street_name' => $s['street'], 'house_number' => $s['house_number'] ?? '', 'postcode' => $s['postal_code'],
            'city' => $s['city'], 'country_id' => $country, 'mail' => $s['email'],
            'user_id' => $c['user_id'], 'owner_id' => $c['user_id'], 'language_id' => $c['language_id'], 'remarks' => $reference];
    }

    private function assertContact(array $remote, array $expected): void
    {
        foreach (['contact_type_id', 'name_1', 'name_2', 'street_name', 'house_number', 'postcode', 'city', 'country_id', 'mail'] as $key) {
            $norm = fn ($v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $v)));
            abort_unless($norm($remote[$key] ?? '') === $norm($expected[$key] ?? ''), 409, 'Bexio-Kontakt stimmt nicht mit Empfängersnapshot überein.');
        }
    }

    private function function(string $action): string
    {
        return match ($action) {
            'send', 'confirm-sent' => 'send', 'cancel' => 'cancel', 'sync' => 'sync', default => 'approve'
        };
    }

    private function providerWrite(Account $actor, Tenant $tenant, object $row, string $action, string $token, string $path, array $payload, SponsorRunBexioClient $client, bool &$writeAttempted): array
    {
        $this->authorize($actor, $tenant, $this->function($action));
        abort_unless(BexioConnectionService::canManage($actor, $tenant)
            && hash_equals($row->connection_hash, $this->binding($tenant))
            && hash_equals($row->configuration_hash, $this->fingerprint($tenant))
            && $this->configuration($tenant)['enabled'], 409, 'Berechtigung oder Verbindung wurde geändert.');
        if ($action !== 'cancel') {
            $fresh = app(SponsorRunService::class)->billingSource($actor, $tenant, $row->pledge_id);
            foreach (['event_revision', 'participant_revision', 'pledge_revision'] as $key) {
                abort_unless($fresh[$key] === $row->snapshot[$key], 409, 'Quelldaten wurden geändert; Vorschau zuerst prüfen.');
            }
        }

        $writeAttempted = true;
        $result = $client->write($token, $path, $payload);
        if ($result['state'] === 'rejected') {
            $writeAttempted = false;
            abort(422, 'Bexio hat die Anfrage eindeutig abgelehnt. Angaben korrigieren und erneut prüfen.');
        }

        return $result;
    }

    private function perform(Account $actor, Tenant $tenant, object $row, string $action, array $input, string $token, SponsorRunBexioClient $client, bool &$writeAttempted): array
    {
        $ref = 'clubunio-sponsor:'.$row->reference;
        if ($action === 'refresh-preview') {
            $source = app(SponsorRunService::class)->billingSource($actor, $tenant, $row->pledge_id);
            abort_unless(! $row->remote_id && $this->configuration($tenant)['enabled'] && $source['total_cents'] > 0, 409);
            abort_if($row->contact_id && $source['recipient'] !== $row->snapshot['recipient'], 409, 'Zugeordneter Empfänger wurde geändert. Fachliche Klärung erforderlich.');

            return ['state' => $row->state, 'configuration_hash' => $this->fingerprint($tenant), 'total_cents' => $source['total_cents'],
                'snapshot_encrypted' => Crypt::encryptString(json_encode($source + ['invoice_date' => now('Europe/Zurich')->toDateString()], JSON_THROW_ON_ERROR))];
        }
        if ($action === 'confirm-sent') {
            Validator::make($input, ['reason' => 'required|string|min:10|max:1000'])->validate();

            return ['state' => 'sent', 'sent_at' => now()->toIso8601String()];
        }
        if ($action === 'sync') {
            if (! $row->contact_id && $row->state === 'contact_uncertain') {
                $contact = $client->findContact($token, $ref);
                abort_unless($contact, 409, 'Kontakt weiterhin unbestätigt. Nicht erneut erstellen.');
                $expected = $this->contactPayload($row->snapshot['recipient'], $this->configuration($tenant),
                    $this->recipientCountry($token, $client, $row->snapshot['recipient']['country']), $ref);
                $this->assertContact($contact, $expected);

                return ['state' => 'contact_ready', 'contact_id' => (int) $contact['id']];
            }
            $remote = $row->remote_id ? $client->read($token, '/2.0/kb_invoice/'.$row->remote_id) : $client->findInvoice($token, $ref);
            abort_unless($remote, 409, 'Bexio-Ergebnis weiterhin unbestätigt. Keine erneute Erstellung.');
            $this->assertInvoice($row, $remote, $ref);
            $state = match ((int) ($remote['kb_item_status_id'] ?? 0)) {
                7 => 'draft', 8, 31 => 'issued', 9 => 'paid', 16 => 'partial', 19 => 'cancelled', default => abort(409)
            };
            if ($row->state === 'send_uncertain' && $state === 'issued') {
                $state = 'send_uncertain';
            } elseif ($row->sent_at && $state === 'issued') {
                $state = 'sent';
            }

            return ['state' => $state, 'remote_id' => (int) $remote['id'], 'remote_status' => (int) $remote['kb_item_status_id'],
                'paid_cents' => $this->cents($remote['total_received_payments'] ?? null), 'credit_cents' => $this->cents($remote['total_credit_vouchers'] ?? null)];
        }
        $c = $this->checkedConfiguration($tenant, $token, $client);
        $expected = $this->contactPayload($row->snapshot['recipient'], $c,
            $this->recipientCountry($token, $client, $row->snapshot['recipient']['country']), $ref);
        if (in_array($action, ['link-contact', 'create-contact'], true)) {
            if ($action === 'link-contact') {
                $data = Validator::make($input, ['contact_id' => 'required|integer|min:1|max:2147483647'])->validate();
                $contact = $client->read($token, '/2.0/contact/'.$data['contact_id']);
            } else {
                $found = $client->findContact($token, $ref);
                if ($found) {
                    $contact = $found;
                } else {
                    $result = $this->providerWrite($actor, $tenant, $row, $action, $token, '/2.0/contact', $expected, $client, $writeAttempted);
                    abort_unless($result['state'] === 'accepted', 409, 'Kontakterstellung nicht bestätigt; Abgleich verwenden.');
                    $contact = $client->read($token, '/2.0/contact/'.$result['id']);
                }
            }
            $this->assertContact($contact, $expected);

            return ['state' => 'contact_ready', 'contact_id' => (int) $contact['id']];
        }
        $this->assertContact($client->read($token, '/2.0/contact/'.$row->contact_id), $expected);
        if ($action === 'create-draft') {
            $s = $row->snapshot;
            $r = $s['recipient'];
            $address = implode("\n", array_filter([$r['company'] ?? '', trim($r['first_name'].' '.$r['last_name']),
                trim($r['street'].' '.($r['house_number'] ?? '')), $r['postal_code'].' '.$r['city'], $r['country']]));
            $payload = array_intersect_key($c, array_flip(['user_id', 'language_id', 'bank_account_id', 'currency_id', 'payment_type_id', 'mwst_type']));
            $payload += ['contact_id' => (int) $row->contact_id, 'contact_address_manual' => $address,
                'title' => $s['title'], 'api_reference' => $ref, 'mwst_is_net' => false, 'show_position_taxes' => true,
                'is_valid_from' => $s['invoice_date'], 'is_valid_to' => CarbonImmutable::parse($s['invoice_date'])->addDays($c['due_days'])->toDateString(),
                'positions' => [['type' => 'KbPositionCustom', 'text' => htmlspecialchars($s['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'amount' => '1', 'unit_id' => $c['unit_id'], 'tax_id' => $c['tax_id'], 'account_id' => $c['account_id'],
                    'unit_price' => app(InvoiceWorkflowService::class)->decimal($row->total_cents)]]];
            $result = $this->providerWrite($actor, $tenant, $row, $action, $token, '/2.0/kb_invoice', $payload, $client, $writeAttempted);
            abort_unless($result['state'] === 'accepted', 409, 'Rechnungserstellung nicht bestätigt; nur Ergebnis abgleichen.');
            $remote = $client->read($token, '/2.0/kb_invoice/'.$result['id']);
            $this->assertInvoice($row, $remote, $ref);
            abort_unless((int) ($remote['kb_item_status_id'] ?? 0) === 7, 409);

            return ['state' => 'draft', 'remote_id' => $result['id'], 'remote_status' => 7];
        }
        $remote = $client->read($token, '/2.0/kb_invoice/'.$row->remote_id);
        $this->assertInvoice($row, $remote, $ref);
        $status = (int) ($remote['kb_item_status_id'] ?? 0);
        abort_unless($action === 'issue' ? $status === 7 : in_array($status, [8, 31], true), 409);
        abort_unless($this->cents($remote['total_received_payments'] ?? null) === 0 && $this->cents($remote['total_credit_vouchers'] ?? null) === 0, 409,
            'Zahlungen oder Gutschriften zuerst separat klären.');
        $payload = $action === 'send' ? ['recipient_email' => TestMailGuard::RECIPIENT, 'subject' => '[ClubUnio TEST] '.$row->snapshot['title'],
            'message' => 'Ihre Rechnung zum Sponsorenlauf: [Network Link]', 'mark_as_open' => false, 'attach_pdf' => true] : [];
        $result = $this->providerWrite($actor, $tenant, $row, $action, $token, '/2.0/kb_invoice/'.$row->remote_id.'/'.$action, $payload, $client, $writeAttempted);
        abort_unless($result['state'] === 'accepted', 409, 'Bexio-Aktion nicht bestätigt; keine automatische Wiederholung.');

        return ['state' => match ($action) {
            'issue' => 'issued', 'send' => 'sent', 'cancel' => 'cancelled'
        },
            ...($action === 'send' ? ['sent_at' => now()->toIso8601String()] : [])];
    }

    public function cents(mixed $value): int
    {
        abort_unless(is_scalar($value) && preg_match('/^\d{1,10}(?:\.\d{1,6})?$/D', (string) $value), 409, 'Bexio-Betrag fehlt oder ist ungültig.');
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');
        $fraction = str_pad($fraction, 6, '0');
        abort_unless(substr($fraction, 2) === '0000', 409, 'Bexio meldet Bruchteile von Rappen.');

        return (int) $whole * 100 + (int) substr($fraction, 0, 2);
    }

    private function assertInvoice(object $row, array $remote, string $reference): void
    {
        abort_unless(($remote['api_reference'] ?? '') === $reference && (int) ($remote['contact_id'] ?? 0) === (int) $row->contact_id
            && (! $row->remote_id || (int) ($remote['id'] ?? 0) === (int) $row->remote_id)
            && $this->cents($remote['total'] ?? null) === (int) $row->total_cents, 409, 'Bexio-Beleg stimmt nicht mit dem Snapshot überein.');
    }

    public function pdf(Account $actor, Tenant $tenant, int $id)
    {
        $this->authorize($actor, $tenant, 'download');
        $row = $this->get($actor, $tenant, $id);
        abort_unless($row->remote_id && hash_equals($row->connection_hash, $this->binding($tenant)), 404);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $client = app(SponsorRunBexioClient::class);
        $client->verify($token);
        $this->assertInvoice($row, $client->read($token, '/2.0/kb_invoice/'.$row->remote_id), 'clubunio-sponsor:'.$row->reference);
        $bytes = $client->pdf($token, $row->remote_id);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Sponsorenlauf-'.$id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    private function audit(Account $actor, Tenant $tenant, int $id, string $action, string $state, ?string $note = null): void
    {
        DB::connection('clubunio')->table('clubora.sponsor_run_billing_audit')->insert(['tenant_id' => $tenant->id, 'invoice_id' => $id,
            'actor_id' => $actor->id, 'action' => $action, 'state' => $state, 'note_encrypted' => $note === null ? null : Crypt::encryptString($note)]);
    }
}
