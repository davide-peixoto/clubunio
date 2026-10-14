<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Shared preparation and isolated simulation. Azure reads happen before database locks.
class BexioDispatchTrialService
{
    public function validatedRequest(Account $actor, Tenant $tenant, int $id): array
    {
        $this->authorize($actor, $tenant);
        $trial = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')
            ->where('tenant_id', $tenant->id)->where('actor_id', $actor->id)->find($id);
        abort_unless($trial && $trial->state === 'ready', 409, 'Keine offene eigene Versandpruefung.');
        abort_if(CarbonImmutable::parse($trial->created_at)->lt(now()->subMinutes(10)), 409, 'Vorschau abgelaufen.');
        $prepared = $this->snapshot($actor, $tenant, $trial->object_type, $trial->object_id, false);
        abort_unless(hash_equals($trial->fingerprint, $prepared['fingerprint']), 409, 'Versandgrundlagen wurden geaendert.');

        return $prepared + ['trial' => $trial];
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['dispatch' => $message]);
    }

    private function snapshot(Account $actor, Tenant $tenant, string $type, int $id, bool $lock = true, ?array $attachments = null): array
    {
        $this->authorize($actor, $tenant);
        $class = match ($type) {
            'invoice' => Invoice::class, 'expense' => Expense::class, default => abort(422)
        };
        $document = $class::where('tenant_id', $tenant->id)->where('active', true)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        $remote = $document->{$type === 'invoice' ? 'bexio_invoice_id' : 'bexio_bill_id'};
        if (trim((string) $remote) !== '') {
            $this->invalid('Bereits mit Bexio verknüpft oder ungeklärte Referenz. Keine Neuanlage.');
        }
        $status = $type === 'invoice' ? $document->workflowStatus : $document->status;
        if (! $status || ! $status->active || $status->tenant_id != $tenant->id || $status->code !== ($type === 'invoice' ? 'released' : 'Z')) {
            $this->invalid('Das Dokument ist nicht für die Übermittlung freigegeben.');
        }
        if ($type === 'invoice' && $document->legacy_id) {
            $this->invalid('Importierte Rechnungen benötigen vor Neuanlage einen gesonderten Abgleich.');
        }
        $member = $document->member;
        if (! $member || $member->tenant_id != $tenant->id || $member->archived_at || ($type === 'expense' && (int) $member->bexio_contact_id <= 0)) {
            $this->invalid('Eine eindeutige Bexio-Kontaktzuordnung fehlt.');
        }
        $lines = $document->activeLines()->orderBy('id')->limit(501)->get();
        if ($lines->isEmpty() || $lines->count() > 500 || $lines->contains(fn ($line) => $line->tenant_id != $tenant->id)) {
            $this->invalid('Es werden 1 bis 500 gültige eigene Positionen benötigt.');
        }
        $money = app(InvoiceWorkflowService::class);
        $positions = [];
        $total = 0;
        foreach ($lines as $line) {
            if ($type === 'invoice') {
                $gross = $money->cents((string) $line->gross_amount);
                $discount = $money->cents((string) ($line->discount ?? '0'));
                $net = $money->cents((string) $line->net_amount);
                if ($discount > $gross || $net !== $gross - $discount || ! trim((string) $line->description)) {
                    $this->invalid('Betrag, Rabatt oder Bezeichnung einer Rechnungsposition ist widersprüchlich.');
                }
                $article = $line->bexio_catalog_article_id ? app(BexioCatalogService::class)->invoiceArticle($tenant, $line->bexio_catalog_article_id) : null;
                if ($article && (int) $article->remote_id !== (int) $line->bexio_product_id) {
                    $this->invalid('Artikelzuordnung wurde verändert.');
                }
                $positions[] = ['id' => $line->id, 'description' => $line->description, 'gross' => $gross, 'discount' => $discount, 'net' => $net, 'article' => $line->bexio_product_id,
                    'catalog_definition' => $article?->only(['id', 'remote_id', 'code', 'provider_name', 'provider_data', 'connection_hash']), 'tax_snapshot' => $line->tax_snapshot];
                if ($document->tax_mode === 'inclusive') {
                    $current = $line->bexio_catalog_tax_id ? app(InvoiceTaxService::class)->snapshot($tenant, $line->bexio_catalog_tax_id, app(InvoiceTaxService::class)->invoiceDate($document)) : null;
                    if (! $current || $current != $line->tax_snapshot) {
                        $this->invalid('Steuerzuordnung fehlt oder hat sich geändert. Position erneut prüfen.');
                    }
                }
            } else {
                $net = $money->cents((string) $line->amount);
                $expenseType = ExpenseType::where('tenant_id', $tenant->id)->where('active', true)->find($line->expense_type_id);
                if ($net <= 0 || ! $expenseType || (int) $expenseType->bexio_account_id <= 0) {
                    $this->invalid('Positiver Spesenbetrag und Bexio-Buchungskonto je Spesentyp erforderlich.');
                }
                $positions[] = ['id' => $line->id, 'description' => $expenseType->name, 'net' => $net, 'account' => $expenseType->bexio_account_id];
            }
            $total += $net;
        }
        if ($total <= 0) {
            $this->invalid('Nullrechnungen benötigen einen gesonderten Abschluss; keine Bexio-Neuanlage.');
        }
        if ($type === 'invoice') {
            $summary = app(InvoiceTaxService::class)->summary($document, true);
            if ($summary['unrounded_cents'] !== $total) {
                $this->invalid('Positionssummen und Endbetrag sind widersprüchlich.');
            }
            if ((int) $document->rounding_cents !== $summary['rounding_cents']) {
                $this->invalid('Lokale Rundung ist widersprüchlich.');
            }
            $total = $summary['total_cents'];
            if ($total <= 0) {
                $this->invalid('Der gerundete Endbetrag muss positiv sein; Nullrechnungen benötigen einen gesonderten Abschluss.');
            }
        }
        $request = app(BexioPayloadBuilder::class)->build($tenant, $document, $positions, $total);
        if ($type === 'expense') {
            $service = app(BexioAttachmentService::class);
            $plan = $service->plan($tenant, $lines);
            if (! $lock) {
                $attachments = $service->prepare($tenant, $id, $plan);
            } elseif (! $attachments || ! hash_equals($plan['source_hash'], $attachments['source_hash'])) {
                $this->invalid('Belegzuordnung wurde während der Prüfung geändert. Bitte erneut prüfen.');
            }
        }
        $snapshot = ['version' => 4, 'type' => $type, 'document' => $document->getAttributes(), 'contact' => $member->bexio_contact_id,
            'billing_revision' => $type === 'invoice' ? $member->billing_bexio_revision : null, 'positions' => $positions, 'request' => $request, 'attachments' => $attachments];

        return ['fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'total_cents' => $total, 'line_count' => count($positions),
            'attachment_count' => count($attachments['files'] ?? []), 'attachment_bytes' => $attachments['bytes'] ?? 0, '_attachments' => $attachments, '_request' => $request];
    }

    public function preview(Account $actor, Tenant $tenant, string $type, int $id): int
    {
        $this->authorize($actor, $tenant);
        $prepared = $this->snapshot($actor, $tenant, $type, $id, false);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $type, $id, $prepared) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($actor, $tenant, $type, $id, true, $prepared['_attachments']);
            if (! hash_equals($prepared['fingerprint'], $snapshot['fingerprint'])) {
                $this->invalid('Dokument wurde während der Prüfung geändert. Bitte erneut prüfen.');
            }
            unset($snapshot['_attachments'], $snapshot['_request']);
            $query = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials');
            $existing = (clone $query)->where('tenant_id', $tenant->id)->where('object_type', $type)->where('object_id', $id)->where('fingerprint', $snapshot['fingerprint'])->first();
            if ($existing) {
                if ($existing->actor_id != $actor->id) {
                    $this->invalid('Für diesen Stand besteht bereits eine Prüfung durch eine andere Person.');
                }
                if ($existing->state === 'ready') {
                    (clone $query)->where('id', $existing->id)->update(['created_at' => now()]);
                }

                return (int) $existing->id;
            }

            if ((clone $query)->where('tenant_id', $tenant->id)->where('object_type', $type)->where('object_id', $id)->whereIn('state', ['uncertain', 'running'])->exists()) {
                $this->invalid('Ein vorheriger Testlauf hat einen unklaren Ausgang. Keine neue Übermittlung, auch nach Datenänderung.');
            }

            return $query->insertGetId($snapshot + ['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => $type, 'object_id' => $id, 'reference' => (string) Str::uuid()]);
        });
    }

    public function simulate(Account $actor, Tenant $tenant, int $id, string $outcome): void
    {
        $this->authorize($actor, $tenant);
        abort_if(DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('source_trial_id', $id)->exists(), 409, 'Echter Sandbox-Auftrag vorhanden.');
        abort_unless(in_array($outcome, ['simulated', 'rejected', 'uncertain'], true), 422);
        $trial = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('actor_id', $actor->id)->find($id);
        abort_unless($trial, 404);
        if ($trial->state !== 'ready') {
            return;
        }
        if (CarbonImmutable::parse($trial->created_at)->lt(now()->subMinutes(10))) {
            $this->invalid('Vorschau abgelaufen. Bitte erneut prüfen.');
        }
        $prepared = $this->snapshot($actor, $tenant, $trial->object_type, $trial->object_id, false);
        $token = (string) Str::uuid();
        $claimed = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $prepared, $token) {
            $this->authorize($actor, $tenant);
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $query = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('actor_id', $actor->id)->where('id', $id);
            $trial = (clone $query)->lockForUpdate()->first();
            abort_unless($trial, 404);
            if ($trial->state !== 'ready') {
                return false;
            }
            if (DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')
                ->where('tenant_id', $tenant->id)->where('object_type', $trial->object_type)->where('object_id', $trial->object_id)
                ->where('id', '<>', $id)->whereIn('state', ['running', 'uncertain'])->exists()) {
                $this->invalid('Ein anderer Testlauf dieses Dokuments muss zuerst geklärt werden.');
            }
            if (CarbonImmutable::parse($trial->created_at)->lt(now()->subMinutes(10))) {
                $this->invalid('Vorschau abgelaufen. Bitte erneut prüfen.');
            }
            $fresh = $this->snapshot($actor, $tenant, $trial->object_type, $trial->object_id, true, $prepared['_attachments']);
            if (! hash_equals($prepared['fingerprint'], $fresh['fingerprint'])) {
                $this->invalid('Dokument wurde während der Prüfung geändert. Bitte erneut prüfen.');
            }
            if (! hash_equals($trial->fingerprint, $fresh['fingerprint'])) {
                $this->invalid('Dokument oder Zuordnungen wurden geändert. Bitte erneut prüfen.');
            }
            $query->update(['state' => 'running', 'run_started_at' => now(), 'execution_token' => $token, 'completed_at' => null]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $client = BexioSubmissionClient::simulation($trial->object_type, $prepared['attachment_count'], $outcome);
        $result = ['state' => 'rejected'];
        try {
            $payload = $prepared['_request']['payload'];
            $fileIds = [];
            foreach ($prepared['_attachments']['files'] ?? [] as $file) {
                $bytes = app(AzureDocumentReader::class)->read('azure.blob://files/', $file['source']);
                if (strlen($bytes) !== $file['size'] || ! hash_equals($file['sha256'], hash('sha256', $bytes))) {
                    throw new \RuntimeException('Attachment changed');
                }
                $step = $this->startStep($id, $token, 'upload');
                $result = $client->upload($bytes, $file['filename'], $file['mime']);
                unset($bytes);
                $this->endStep($step, $result);
                if ($result['state'] !== 'accepted') {
                    break;
                }
                $fileIds[] = $result['id'];
            }
            if (count($fileIds) === $prepared['attachment_count']) {
                if ($trial->object_type === 'expense') {
                    $payload['attachment_ids'] = $fileIds;
                }
                $step = $this->startStep($id, $token, 'create');
                $result = $client->create($prepared['_request']['path'], $payload);
                $this->endStep($step, $result);
            }
        } catch (\Throwable) {
            // A crash after claiming a step is deliberately not assumed to be a rejection.
            $started = DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->where('trial_id', $id)->where('state', 'started')->exists();
            $result = ['state' => $started ? 'uncertain' : 'rejected'];
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $token, $result) {
            $query = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('id', $id)->where('execution_token', $token)->where('state', 'running');
            $trial = (clone $query)->lockForUpdate()->first();
            if (! $trial) {
                return;
            }
            $state = $result['state'] === 'accepted' ? 'simulated' : $result['state'];
            $query->update(['state' => $state, 'completed_at' => now(), 'execution_token' => null,
                'simulation_reference' => $state === 'simulated' ? 'simulation:'.$result['id'] : null]);
            $this->auditTrial($actor, $tenant, $trial, $state);
        });
    }

    private function startStep(int $id, string $token, string $operation): int
    {
        return DB::connection('clubunio')->transaction(function () use ($id, $token, $operation) {
            $trial = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('id', $id)->lockForUpdate()->first();
            abort_unless($trial && $trial->state === 'running' && $trial->execution_token === $token, 409);

            return DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->insertGetId(['trial_id' => $id, 'operation' => $operation, 'state' => 'started']);
        });
    }

    private function endStep(int $id, array $result): void
    {
        DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->where('id', $id)->where('state', 'started')->update([
            'state' => $result['state'], 'http_status' => $result['http_status'] ?? null,
            'simulation_reference' => isset($result['id']) ? 'simulation:'.$result['id'] : null]);
    }

    private function auditTrial(Account $actor, Tenant $tenant, object $trial, string $state): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'bexio_dispatch_trials', 'object_id' => $trial->id,
            'after_value' => json_encode(['mode' => 'simulation', 'state' => $state, 'reference' => $trial->reference], JSON_THROW_ON_ERROR)]);
    }

    public function resolveSimulation(Account $actor, Tenant $tenant, int $id, string $decision): void
    {
        $this->authorize($actor, $tenant);
        abort_unless(in_array($decision, ['found', 'not_created'], true), 422);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $decision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $query = DB::connection('clubunio')->table('clubora.bexio_dispatch_trials')->where('tenant_id', $tenant->id)->where('actor_id', $actor->id)->where('id', $id);
            $trial = (clone $query)->lockForUpdate()->first();
            abort_unless($trial, 404);
            if (! in_array($trial->state, ['uncertain', 'rejected', 'running'], true)) {
                $this->invalid('Nur fehlgeschlagene Testläufe können geklärt werden.');
            }
            if ($trial->state === 'running' && CarbonImmutable::parse($trial->run_started_at)->gt(now()->subMinutes(15))) {
                $this->invalid('Testlauf läuft noch. Bitte später erneut prüfen.');
            }
            $last = DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->where('trial_id', $id)->orderByDesc('id')->first();
            if ($decision === 'found' && (! $last || $last->operation !== 'create' || $trial->state === 'rejected')) {
                $this->invalid('Es wurde keine unklare Dokumentanlage protokolliert.');
            }
            $state = $decision === 'found' ? 'simulated' : 'ready';
            $query->update(['state' => $state, 'execution_token' => null, 'created_at' => now(),
                'simulation_reference' => $decision === 'found' ? 'simulation:reconciled:'.$trial->reference : null,
                'completed_at' => $decision === 'found' ? now() : null]);
            DB::connection('clubunio')->table('clubora.bexio_dispatch_steps')->insert(['trial_id' => $id, 'operation' => 'resolve', 'state' => $decision === 'found' ? 'accepted' : 'reset']);
            $this->auditTrial($actor, $tenant, $trial, $state);
        });
    }
}
