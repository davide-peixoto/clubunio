<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BexioSandboxDispatchService
{
    private function table()
    {
        return DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches');
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'bexio', 'view');
    }

    private function binding(Tenant $tenant)
    {
        $binding = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($binding && hash_equals($binding->connection_hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant)),
            409, 'Die bestätigte Sandbox-Verbindung fehlt oder wurde geändert.');

        return $binding;
    }

    private function client(): BexioSandboxClient
    {
        return app(BexioSandboxClient::class);
    }

    public function prepareTrial(Account $actor, Tenant $tenant, int $trialId): int
    {
        $this->authorize($actor, $tenant);
        $binding = $this->binding($tenant);
        $existing = $this->table()->where('tenant_id', $tenant->id)->where('source_trial_id', $trialId)->first();
        if ($existing) {
            $this->checked($actor, $tenant, $existing->id);

            return (int) $existing->id;
        }
        $prepared = app(BexioDispatchTrialService::class)->validatedRequest($actor, $tenant, $trialId);
        $trial = $prepared['trial'];
        $kind = $trial->object_type;
        $document = $this->document((object) ['kind' => $kind, 'tenant_id' => $tenant->id, $kind.'_id' => $trial->object_id]);
        $newRecipient = $kind === 'invoice' && ($trial->sandbox_recipient_id ?? null);
        if ($newRecipient) {
            app(BexioSandboxRecipientService::class)->resolve($tenant, $document, (int) $trial->sandbox_recipient_id, (int) $prepared['_request']['payload']['contact_id']);
        }
        abort_unless(! $document->legacy_id && ($newRecipient || (int) $document->member_id === (int) ($kind === 'invoice' ? $binding->member_id : $binding->supplier_id)), 409, 'Nur registrierte synthetische Sandbox-Mitglieder.');
        $hash = $this->snapshot($document);
        $reference = (string) Str::uuid();
        $payload = $prepared['_request']['payload'];
        $payload[$kind === 'invoice' ? 'api_reference' : 'vendor_ref'] = 'clubora-sandbox:'.$reference;

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $trial, $kind, $document, $hash, $reference, $payload, $prepared) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $binding = $this->binding($tenant);
            abort_unless(hash_equals($hash, $this->snapshot($document)), 409);
            $existing = $this->table()->where('tenant_id', $tenant->id)->where($kind.'_id', $document->id)->first();
            if ($existing) {
                abort_unless((int) $existing->source_trial_id === (int) $trial->id, 409, 'Vorherigen Sandbox-Auftrag zuerst klaeren.');

                return (int) $existing->id;
            }
            $id = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'kind' => $kind,
                $kind.'_id' => $document->id, 'source_trial_id' => $trial->id, 'expected_cents' => $prepared['total_cents'],
                'reference' => $reference, 'connection_hash' => $binding->connection_hash, 'snapshot_hash' => $hash,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            $this->step($id, $actor, 'prepare', 'ready');

            return $id;
        });
    }

    public function statusDocument(Account $actor, Tenant $tenant, string $kind, int $id)
    {
        $this->authorize($actor, $tenant);
        abort_unless(in_array($kind, ['invoice', 'expense'], true), 422);
        $row = $this->table()->where('tenant_id', $tenant->id)->where($kind.'_id', $id)->where('kind', $kind)->where('state', 'accepted')->first();
        abort_unless($row, 409, 'Nur erfolgreich uebertragene Sandbox-Testbelege.');
        $row = $this->checked($actor, $tenant, $row->id);
        $doc = $this->document($row);
        abort_unless((string) $doc->{$kind === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id'} === $row->remote_id, 409, 'Sandbox-Verknuepfung geaendert.');

        return $row;
    }

    public function readStatus(Account $actor, Tenant $tenant, string $kind, int $id): array
    {
        $row = $this->statusDocument($actor, $tenant, $kind, $id);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $data = $this->client()->get($token, ($kind === 'invoice' ? '/2.0/kb_invoice/' : '/4.0/purchase/bills/').$row->remote_id);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $key = $kind === 'invoice' ? 'contact_id' : 'supplier_id';
        abort_unless(($data[$kind === 'invoice' ? 'api_reference' : 'vendor_ref'] ?? '') === 'clubora-sandbox:'.$row->reference
            && (int) ($data[$key] ?? 0) === (int) $payload[$key], 409, 'Sandbox-Identitaet stimmt nicht ueberein.');

        return $data;
    }

    public function verifyReconnection(Account $actor, Tenant $tenant): void
    {
        $this->authorize($actor, $tenant);
        abort_if(DB::connection('clubunio')->table('clubora.bexio_invoice_actions as actions')
            ->join('clubora.bexio_sandbox_dispatches as dispatch', 'dispatch.id', '=', 'actions.dispatch_id')
            ->where('dispatch.tenant_id', $tenant->id)->where('actions.state', '<>', 'accepted')->exists(), 409, 'Offene Rechnungsaktionen zuerst mit der bestehenden Verbindung klaeren.');
        abort_if(DB::connection('clubunio')->table('clubora.bexio_financial_actions as actions')->join('clubora.bexio_sandbox_dispatches as dispatch', 'dispatch.id', '=', 'actions.dispatch_id')
            ->where('dispatch.tenant_id', $tenant->id)->whereIn('actions.state', ['running', 'uncertain'])->exists(), 409, 'Offene Finanzaktionen zuerst klaeren.');
        $registry = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)->first();
        abort_unless($registry, 409);
        $hash = app(BexioSandboxFixtureService::class)->connectionHash($tenant);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $this->client()->get($token, '/3.0/files');
        $recipients = DB::connection('clubunio')->table('clubora.bexio_sandbox_recipients')->where('tenant_id', $tenant->id)->where('connection_hash', $registry->connection_hash)->get();
        foreach ($recipients as $recipient) {
            abort_if(in_array($recipient->state, ['running', 'uncertain'], true), 409, 'Unklaren Testkontakt zuerst abgleichen.');
            if ($recipient->state === 'accepted') {
                app(BexioSandboxRecipientService::class)->assertContact($recipient, $this->client()->get($token, '/2.0/contact/'.$recipient->remote_id));
            }
        }
        $baseline = $this->client()->get($token, '/2.0/kb_invoice/'.$registry->remote_invoice_id);
        abort_unless(($baseline['api_reference'] ?? '') === BexioSandboxFixtureService::REFERENCE
            && (int) ($baseline['contact_id'] ?? 0) === (int) $registry->remote_contact_id, 409);
        $rows = $this->table()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        foreach ($rows as $row) {
            abort_if(in_array($row->state, ['running', 'uncertain'], true), 409, 'Unklaren Versand zuerst prüfen.');
            if ($row->remote_id) {
                $data = $this->client()->get($token, ($row->kind === 'invoice' ? '/2.0/kb_invoice/' : '/4.0/purchase/bills/').$row->remote_id);
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                $contactKey = $row->kind === 'invoice' ? 'contact_id' : 'supplier_id';
                abort_unless(($data[$row->kind === 'invoice' ? 'api_reference' : 'vendor_ref'] ?? '') === 'clubora-sandbox:'.$row->reference
                    && (int) ($data[$contactKey] ?? 0) === (int) $payload[$contactKey], 409);
            }
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $hash, $registry, $rows, $recipients) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            abort_unless(hash_equals($hash, app(BexioSandboxFixtureService::class)->connectionHash($tenant))
                && $rows->toJson() === $this->table()->where('tenant_id', $tenant->id)->orderBy('id')->get()->toJson(), 409);
            $count = DB::connection('clubunio')->table('clubora.bexio_sandbox_fixtures')->where('tenant_id', $tenant->id)
                ->where('connection_hash', $registry->connection_hash)->update(['connection_hash' => $hash]);
            abort_unless($count === 1, 409);
            $this->table()->where('tenant_id', $tenant->id)->update(['connection_hash' => $hash]);
            abort_unless($recipients->toJson() === DB::connection('clubunio')->table('clubora.bexio_sandbox_recipients')->where('tenant_id', $tenant->id)->where('connection_hash', $registry->connection_hash)->get()->toJson(), 409);
            DB::connection('clubunio')->table('clubora.bexio_sandbox_recipients')->where('tenant_id', $tenant->id)->where('connection_hash', $registry->connection_hash)->where('state', 'accepted')->update(['connection_hash' => $hash]);
            foreach ($rows as $row) {
                $this->step($row->id, $actor, 'rebind', 'accepted');
            }
        });
    }

    public function resumeRejectedUpload(Account $actor, Tenant $tenant, int $id): void
    {
        $row = $this->checked($actor, $tenant, $id);
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $this->client()->get($token, '/3.0/files');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->checked($actor, $tenant, $id);
            $steps = DB::connection('clubunio')->table('clubora.bexio_sandbox_steps')->where('dispatch_id', $id);
            abort_unless($row->kind === 'expense' && $row->state === 'rejected' && in_array($row->http_status, [401, 403], true)
                && ! $row->attachment_id && json_decode($row->uploaded_ids, true) === [] && ! $row->remote_id && ! (clone $steps)->where('operation', 'create')->exists()
                && (clone $steps)->where('operation', 'upload')->where('state', 'rejected')->whereIn('http_status', [401, 403])->exists(), 409);
            $this->table()->where('id', $id)->update(['state' => 'ready', 'http_status' => null, 'started_at' => null, 'finished_at' => null]);
            $this->step($id, $actor, 'resume', 'ready');
        });
    }

    public function prepare(Account $actor, Tenant $tenant, string $kind): int
    {
        $this->authorize($actor, $tenant);
        abort_unless(in_array($kind, ['invoice', 'expense'], true), 422);
        $binding = $this->binding($tenant);
        $existing = $this->table()->where('tenant_id', $tenant->id)->where('kind', $kind)->whereNull('source_trial_id')->first();
        if ($existing) {
            return (int) $existing->id;
        }
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $member = Member::where('tenant_id', $tenant->id)->findOrFail($kind === 'invoice' ? $binding->member_id : $binding->supplier_id);
        abort_if($member->legacy_id || $member->archived_at, 409);
        $contact = $this->client()->get($token, '/2.0/contact/'.$member->bexio_contact_id);
        $label = $kind === 'invoice' ? 'Member' : 'Supplier';
        abort_unless(($contact['remarks'] ?? '') === 'clubora-sandbox-fixture:v1:'.strtolower($label)
            && ($contact['name_1'] ?? '') === 'Clubora Sandbox '.$label.' v1', 409, 'Kein synthetischer Sandbox-Kontakt.');
        $reference = (string) Str::uuid();
        $payload = $this->payload($token, $kind, (int) $member->bexio_contact_id, $reference);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $kind, $binding, $member, $reference, $payload) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $this->binding($tenant);
            $existing = $this->table()->where('tenant_id', $tenant->id)->where('kind', $kind)->whereNull('source_trial_id')->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $notes = 'SANDBOX Webtest - keine echte Forderung, keine Zahlung, kein E-Mail-Versand.';
            if ($kind === 'invoice') {
                $document = new Invoice;
                $document->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'notes' => $notes,
                    'workflow_status_id' => app(InvoiceWorkflowService::class)->status($tenant, 'draft')])->save();
                $line = new InvoiceLine;
                $line->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $document->id,
                    'description' => 'Clubora Sandbox Webtest', 'gross_amount' => '25.00', 'discount' => '0.00',
                    'net_amount' => '25.00', 'contribution' => false])->save();
            } else {
                $status = DB::connection('clubunio')->table('clubora.expense_statuses')->where('tenant_id', $tenant->id)
                    ->where('active', true)->where('code', 'O')->value('id');
                abort_unless($status, 422, 'Offener Spesenstatus fehlt.');
                $document = new Expense;
                $document->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'notes' => $notes, 'status_id' => $status])->save();
                $line = new ExpenseLine;
                $line->forceFill(['tenant_id' => $tenant->id, 'expense_id' => $document->id, 'amount' => '15.00',
                    'notes' => 'Synthetische Testspesen mit generiertem PDF-Beleg'])->save();
            }
            $id = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'kind' => $kind,
                'reference' => $reference, $kind.'_id' => $document->id, 'connection_hash' => $binding->connection_hash,
                'snapshot_hash' => $this->snapshot($document), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            $this->step($id, $actor, 'prepare', 'ready');

            return $id;
        });
    }

    private function payload(string $token, string $kind, int $contact, string $reference): array
    {
        $client = $this->client();
        $pick = function (string $path, string $field, string $value) use ($client, $token): int {
            $rows = $client->get($token, $path);
            $matches = array_values(array_filter($rows, fn ($row) => (string) ($row[$field] ?? '') === $value));
            abort_unless(count($matches) === 1 && (int) ($matches[0]['id'] ?? 0) > 0, 422, 'Sandbox-Stammdaten nicht eindeutig.');

            return (int) $matches[0]['id'];
        };
        $user = $client->get($token, '/3.0/users/me');
        abort_unless((int) ($user['id'] ?? 0) > 0, 422);
        $date = now('Europe/Zurich');
        $reference = 'clubora-sandbox:'.$reference;
        if ($kind === 'invoice') {
            $banks = $client->get($token, '/3.0/banking/accounts');
            abort_unless(count($banks) === 1 && (int) ($banks[0]['id'] ?? 0) > 0, 422, 'Sandbox-Bankkonto nicht eindeutig.');

            return ['title' => 'Clubora Sandbox Webtest - nicht versenden', 'contact_id' => $contact, 'user_id' => (int) $user['id'],
                'language_id' => $pick('/2.0/language', 'name', 'Deutsch'), 'currency_id' => $pick('/3.0/currencies', 'name', 'CHF'),
                'bank_account_id' => (int) $banks[0]['id'],
                'payment_type_id' => $pick('/2.0/payment_type', 'name', 'Rechnung'),
                'mwst_type' => 2, 'mwst_is_net' => true, 'show_position_taxes' => false,
                'is_valid_from' => $date->toDateString(), 'is_valid_to' => $date->copy()->addDays(30)->toDateString(),
                'api_reference' => $reference, 'positions' => [['type' => 'KbPositionCustom', 'amount' => '1.000000',
                    'unit_id' => $pick('/2.0/unit', 'name', 'Stk'), 'account_id' => $pick('/2.0/accounts', 'account_no', '3200'),
                    'tax_id' => $pick('/3.0/taxes', 'code', 'UN81'), 'text' => 'Clubora Sandbox Webtest',
                    'unit_price' => '25.000000', 'discount_in_percent' => '0.000000']]];
        }

        return ['title' => 'Clubora Sandbox Testspesen - nicht bezahlen', 'supplier_id' => $contact,
            'contact_partner_id' => (int) $user['id'], 'vendor_ref' => $reference,
            'bill_date' => $date->toDateString(), 'due_date' => $date->copy()->addDays(30)->toDateString(),
            'manual_amount' => false, 'amount_calc' => 15.0, 'currency_code' => 'CHF', 'item_net' => false,
            'address' => ['lastname_company' => 'Clubora Sandbox Supplier v1', 'address_line' => 'Teststrasse 1',
                'postcode' => '4450', 'city' => 'Sissach', 'country_code' => 'CH', 'type' => 'COMPANY'],
            'attachment_ids' => [], 'discounts' => [], 'line_items' => [['position' => 0, 'title' => 'Clubora Sandbox Testspesen',
                'amount' => 15.0, 'booking_account_id' => $pick('/2.0/accounts', 'account_no', '4200')]]];
    }

    private function document($row)
    {
        $class = $row->kind === 'invoice' ? Invoice::class : Expense::class;

        return $class::where('tenant_id', $row->tenant_id)->findOrFail($row->{$row->kind.'_id'});
    }

    private function snapshot($document): string
    {
        $document->refresh();

        return hash('sha256', json_encode([$document->getAttributes(), $document->lines()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    private function checked(Account $actor, Tenant $tenant, int $id)
    {
        $this->authorize($actor, $tenant);
        $binding = $this->binding($tenant);
        $row = $this->table()->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row && hash_equals($binding->connection_hash, $row->connection_hash), 409);
        $doc = $this->document($row);
        $recipient = $row->source_trial_id ? DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('id', $row->source_trial_id)->value('sandbox_recipient_id') : null;
        if ($recipient) {
            abort_unless($row->kind === 'invoice', 409);
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            app(BexioSandboxRecipientService::class)->resolve($tenant, $doc, (int) $recipient, (int) $payload['contact_id']);
        }
        abort_unless(! $doc->legacy_id && $doc->active && ($recipient || (int) $doc->member_id === (int) ($row->kind === 'invoice' ? $binding->member_id : $binding->supplier_id)), 409);
        if ($row->state !== 'accepted') {
            abort_unless(hash_equals($row->snapshot_hash, $this->snapshot($doc)), 409, 'Testbeleg wurde geändert. Übertragung gesperrt.');
        }

        return $row;
    }

    public function send(Account $actor, Tenant $tenant, int $id): void
    {
        $row = $this->checked($actor, $tenant, $id);
        if ($row->state === 'accepted') {
            return;
        }
        abort_unless($row->state === 'ready', 409, 'Bereits gestartet. Nur lesender Abgleich erlaubt.');
        $prepared = $row->source_trial_id ? app(BexioDispatchTrialService::class)->validatedRequest($actor, $tenant, $row->source_trial_id) : null;
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        if ($row->kind === 'invoice') {
            app(BexioCatalogService::class)->verifyRemoteArticles($tenant, $token, $payload['positions']);
            app(InvoiceTaxService::class)->verifyProvider($this->document($row), $token);
        }
        $contact = $this->client()->get($token, '/2.0/contact/'.$payload[$row->kind === 'invoice' ? 'contact_id' : 'supplier_id']);
        $recipient = $row->source_trial_id ? DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('id', $row->source_trial_id)->value('sandbox_recipient_id') : null;
        if ($recipient) {
            app(BexioSandboxRecipientService::class)->assertContact(app(BexioSandboxRecipientService::class)->resolve($tenant, $this->document($row), (int) $recipient, (int) $payload['contact_id']), $contact);
        } else {
            $label = $row->kind === 'invoice' ? $this->invoiceContactLabel($tenant, (int) $payload['contact_id']) : 'Supplier';
            abort_unless(($contact['remarks'] ?? '') === 'clubora-sandbox-fixture:v1:'.strtolower($label)
                && ($contact['name_1'] ?? '') === 'Clubora Sandbox '.$label.' v1', 409, 'Sandbox-Kontakt wurde geändert.');
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->checked($actor, $tenant, $id);
            abort_unless($row->state === 'ready', 409);
            if ($row->kind === 'invoice' && $row->source_trial_id) {
                app(BexioDispatchTrialService::class)->validatedRequest($actor, $tenant, $row->source_trial_id);
            }
            $this->table()->where('id', $id)->update(['state' => 'running', 'started_at' => now()]);
            $this->step($id, $actor, 'send', 'started');
        });
        try {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($row->kind === 'expense' && ! $row->source_trial_id) {
                $pdf = new Dompdf;
                $pdf->loadHtml('<h1>Clubora Sandbox Testbeleg</h1><p>Synthetische Spesen: CHF 15.00</p><p>Keine echte Ausgabe. Nicht bezahlen.</p><p>'.e($row->reference).'</p>');
                $pdf->render();
                $this->step($id, $actor, 'upload', 'started');
                $result = $this->client()->send($token, 'upload', [], $pdf->output());
                $this->step($id, $actor, 'upload', $result['state'], $result['http_status']);
                if ($result['state'] !== 'accepted') {
                    $this->finishFailure($id, $result);

                    return;
                }
                $this->table()->where('id', $id)->update(['attachment_id' => $result['id']]);
                $payload['attachment_ids'] = [$result['id']];
            }
            if ($row->kind === 'expense' && $row->source_trial_id) {
                $fileIds = [];
                foreach ($prepared['_attachments']['files'] ?? [] as $file) {
                    $bytes = app(AzureDocumentReader::class)->read('azure.blob://files/', $file['source']);
                    abort_unless(strlen($bytes) === $file['size'] && hash_equals($file['sha256'], hash('sha256', $bytes)), 409, 'Anhang wurde geaendert.');
                    $this->step($id, $actor, 'upload', 'started');
                    $result = $this->client()->send($token, 'upload', [], $bytes, $file['filename'], $file['mime']);
                    $this->step($id, $actor, 'upload', $result['state'], $result['http_status']);
                    if ($result['state'] !== 'accepted') {
                        $this->finishFailure($id, $result);

                        return;
                    }
                    $fileIds[] = $result['id'];
                    $this->table()->where('id', $id)->update(['uploaded_ids' => json_encode($fileIds, JSON_THROW_ON_ERROR)]);
                }
                $payload['attachment_ids'] = $fileIds;
            }
            $this->checked($actor, $tenant, $id);
            $this->step($id, $actor, 'create', 'started');
            $result = $this->client()->send($token, $row->kind, $payload);
            $this->step($id, $actor, 'create', $result['state'], $result['http_status']);
            if ($result['state'] !== 'accepted') {
                $this->finishFailure($id, $result);

                return;
            }
            // Persist the remote ID before read-back; a read timeout must not lose it.
            $this->table()->where('id', $id)->update(['remote_id' => $result['id'], 'http_status' => $result['http_status']]);
            $this->confirm($actor, $tenant, $id, $token, $result['id']);
        } catch (\Throwable $error) {
            $this->finishFailure($id, ['state' => 'uncertain', 'http_status' => (int) $error->getCode() ?: null]);
            $this->step($id, $actor, 'send', 'uncertain');
        }
    }

    private function finishFailure(int $id, array $result): void
    {
        $this->table()->where('id', $id)->where('state', 'running')->update([
            'state' => $result['state'], 'http_status' => $result['http_status'], 'finished_at' => now()]);
    }

    public function invoiceContactLabel(Tenant $tenant, int $contactId): string
    {
        $binding = $this->binding($tenant);
        if (DB::connection('clubunio')->table('clubora.bexio_sandbox_recipients')->where('tenant_id', $tenant->id)->where('connection_hash', $binding->connection_hash)
            ->where('state', 'accepted')->where('remote_id', $contactId)->exists()) {
            return 'Testmitglied';
        }
        if ($contactId === (int) $binding->remote_contact_id) {
            return 'Member';
        }
        $parent = Member::where('tenant_id', $tenant->id)->whereNull('legacy_id')->whereNull('archived_at')->findOrFail($binding->parent_id);
        abort_unless($contactId > 0 && $contactId === (int) $parent->bexio_contact_id, 409, 'Kein registrierter Sandbox-Empfaenger.');

        return 'Parent';
    }

    private function step(int $id, Account $actor, string $operation, string $state, ?int $status = null): void
    {
        DB::connection('clubunio')->table('clubora.bexio_sandbox_steps')->insert(['dispatch_id' => $id,
            'actor_id' => $actor->id, 'operation' => $operation, 'state' => $state, 'http_status' => $status]);
    }

    public function reconcile(Account $actor, Tenant $tenant, int $id): void
    {
        $row = $this->checked($actor, $tenant, $id);
        if ($row->state === 'accepted') {
            return;
        }
        abort_unless(in_array($row->state, ['running', 'uncertain', 'rejected'], true), 409);
        abort_if($row->state === 'running' && CarbonImmutable::parse($row->started_at)->isAfter(now()->subMinutes(3)), 409, 'Übertragung läuft noch.');
        $token = app(BexioConnectionService::class)->accessToken($tenant);
        $this->client()->verify($token);
        $remoteId = $row->remote_id;
        if (! $remoteId) {
            $marker = 'clubora-sandbox:'.$row->reference;
            $result = $this->client()->get($token, $row->kind === 'invoice' ? '/2.0/kb_invoice' : '/4.0/purchase/bills',
                $row->kind === 'invoice' ? ['limit' => 500] : ['limit' => 500, 'vendor_ref' => $marker]);
            $items = $row->kind === 'invoice' ? $result : ($result['data'] ?? []);
            abort_if(count($items) >= 500 || ($result['paging']['page_count'] ?? 1) > 1, 409, 'Suchergebnis nicht vollständig.');
            $matches = array_values(array_filter($items, fn ($item) => ($item[$row->kind === 'invoice' ? 'api_reference' : 'vendor_ref'] ?? '') === $marker));
            $this->step($id, $actor, 'reconcile', count($matches) === 1 ? 'found' : 'unresolved');
            abort_unless(count($matches) === 1, 409, 'Nicht eindeutig gefunden. Wiederholung bleibt gesperrt.');
            $remoteId = (string) $matches[0]['id'];
        }
        $this->confirm($actor, $tenant, $id, $token, $remoteId);
    }

    private function confirm(Account $actor, Tenant $tenant, int $id, string $token, string $remoteId): void
    {
        $row = $this->checked($actor, $tenant, $id);
        $remote = $this->client()->get($token, ($row->kind === 'invoice' ? '/2.0/kb_invoice/' : '/4.0/purchase/bills/').$remoteId);
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        $invoice = $row->kind === 'invoice';
        abort_unless((string) ($remote['id'] ?? '') === $remoteId
            && ($remote[$invoice ? 'api_reference' : 'vendor_ref'] ?? '') === 'clubora-sandbox:'.$row->reference
            && (int) ($remote[$invoice ? 'contact_id' : 'supplier_id'] ?? 0) === (int) $payload[$invoice ? 'contact_id' : 'supplier_id']
            && (float) ($remote[$invoice ? 'total' : 'amount_calc'] ?? -1) === ($row->expected_cents !== null ? (float) ($row->expected_cents / 100) : ($invoice ? 25.0 : 15.0))
            && (string) ($remote[$invoice ? 'kb_item_status_id' : 'status'] ?? '') === ($invoice ? '7' : 'DRAFT'), 409, 'Remote-Testbeleg stimmt nicht überein.');
        if (! $invoice) {
            abort_unless(($remote['manual_amount'] ?? null) === false && ($remote['currency_code'] ?? '') === 'CHF', 409, 'Berechnung oder Waehrung stimmt nicht ueberein.');
            $expected = $row->source_trial_id ? json_decode($row->uploaded_ids, true, 512, JSON_THROW_ON_ERROR) : [$row->attachment_id];
            abort_unless(! array_diff($expected, $remote['attachment_ids'] ?? []), 409, 'Beleganhang fehlt.');
        } else {
            app(InvoiceTaxService::class)->assertRemote($this->document($row), $payload, $remote);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $remoteId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->checked($actor, $tenant, $id);
            if ($row->state === 'accepted') {
                return;
            }
            $document = $this->document($row);
            $document->{$row->kind === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id'} = $remoteId;
            $document->revision++;
            $document->save();
            $this->table()->where('id', $id)->update(['state' => 'accepted', 'remote_id' => $remoteId, 'finished_at' => now()]);
            $this->step($id, $actor, 'verify', 'accepted');
        });
    }
}
