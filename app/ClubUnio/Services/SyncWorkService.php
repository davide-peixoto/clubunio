<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SyncWorkService
{
    public const LABELS = [
        'invoice_transfer' => 'Bexio · Rechnung übertragen',
        'invoice_action' => 'Bexio · Rechnung freigeben oder stornieren',
        'invoice_status' => 'Bexio · Rechnungsstatus lesen',
        'expense_status' => 'Bexio · Spesenstatus lesen',
        'invoice_sync' => 'Bexio · Rechnungsstatus übernehmen',
        'expense_sync' => 'Bexio · Spesenstatus übernehmen',
        'catalog' => 'Bexio · Artikel und Steuern',
        'sfv_schedule' => 'SFV · Spielplan abrufen',
        'dispatch_result' => 'Bexio · Übertragung klären',
        'invoice_result' => 'Bexio · Rechnungsaktion klären',
        'finance_result' => 'Bexio · Finanzaktion klären',
        'training_review' => 'KI-Training · Anfrage prüfen',
        'azure_upload' => 'Azure · Dokument übertragen',
    ];

    public const ERRORS = [
        'status_401' => 'Anmeldung der Verbindung prüfen',
        'status_403' => 'Berechtigung oder Verbindung prüfen',
        'status_404' => 'Quelldatensatz nicht gefunden',
        'status_409' => 'Daten oder Zuordnung wurden geändert',
        'status_422' => 'Fachliche Angaben prüfen',
        'status_429' => 'Anfragelimit erreicht',
        'status_500' => 'Zielsystem meldet einen Fehler',
        'status_502' => 'Antwort des Zielsystems nicht gültig',
        'status_503' => 'Zielsystem nicht verfügbar',
        'status_504' => 'Zeitüberschreitung; Ergebnis prüfen',
        'operation_failed' => 'Ausführung nicht bestätigt',
        'provider_unconfirmed' => 'Übernahme im Zielsystem nicht bestätigt',
        'status_unconfirmed' => 'Statusabgleich nicht bestätigt',
    ];

    public function table()
    {
        return DB::connection('clubunio')->table('clubora.sync_work_items');
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless(BexioConnectionService::canManage($actor->fresh(), $tenant), 403);
    }

    private function event(int $id, ?Account $actor, string $event, ?string $code = null, ?string $note = null): void
    {
        DB::connection('clubunio')->table('clubora.sync_work_events')->insert([
            'work_item_id' => $id, 'actor_id' => $actor?->id, 'event' => $event, 'code' => $code,
            'encrypted_note' => $note === null ? null : Crypt::encryptString($note),
        ]);
    }

    public function remember(Account $actor, Tenant $tenant, string $operation, int $objectId, array $payload): int
    {
        abort_unless($actor->fresh()?->active && $actor->fresh()?->tenants()->whereKey($tenant->id)->exists(), 403);
        abort_unless(isset(self::LABELS[$operation]), 422);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $hash = in_array($operation, ['sfv_schedule', 'training_review', 'azure_upload'], true) ? null : app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        $key = hash('sha256', $operation.':'.$objectId.':'.$hash.':'.$json);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $operation, $objectId, $json, $hash, $key) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->table()->where('tenant_id', $tenant->id)->where('deduplication_key', $key)->first();
            if ($row) {
                return (int) $row->id;
            }
            $id = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'operation' => $operation, 'object_id' => $objectId,
                'deduplication_key' => $key, 'connection_hash' => $hash, 'encrypted_payload' => Crypt::encryptString($json), 'created_by' => $actor->id]);
            $this->event($id, $actor, 'recorded');

            return $id;
        });
    }

    public function run(Account $actor, Tenant $tenant, string $operation, int $objectId, array $payload = []): mixed
    {
        $id = $this->remember($actor, $tenant, $operation, $objectId, $payload);

        return $this->execute($actor, $tenant, $id);
    }

    public function retry(Account $actor, Tenant $tenant, int $id, int $revision): mixed
    {
        $this->authorize($actor, $tenant);

        return $this->execute($actor, $tenant, $id, $revision);
    }

    public function rememberDocument(Tenant $tenant, int $uploadId): int
    {
        $upload = app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->find($uploadId, ['id', 'kind', 'object_id', 'created_by']);
        abort_unless($upload, 404);
        $key = hash('sha256', 'azure_upload:'.$uploadId);
        $row = $this->table()->where('tenant_id', $tenant->id)->where('deduplication_key', $key)->first();
        if ($row) {
            return (int) $row->id;
        }
        $id = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'operation' => 'azure_upload', 'object_id' => $uploadId,
            'deduplication_key' => $key, 'encrypted_payload' => Crypt::encryptString(json_encode(['kind' => $upload->kind, 'owner_id' => $upload->object_id], JSON_THROW_ON_ERROR)),
            'created_by' => $upload->created_by]);
        $this->event($id, $upload->created_by ? Account::findOrFail($upload->created_by) : null, 'recorded');

        return $id;
    }

    public function runDocument(?Account $actor, Tenant $tenant, int $uploadId): int
    {
        $id = $this->table()->where('tenant_id', $tenant->id)->where('operation', 'azure_upload')->where('object_id', $uploadId)->value('id');
        abort_unless($id, 404);

        return $this->execute($actor, $tenant, (int) $id);
    }

    private function execute(?Account $actor, Tenant $tenant, int $id, ?int $revision = null): mixed
    {
        $token = (string) Str::uuid();
        $row = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $token) {
            $row = $this->table()->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($revision !== null && (int) $row->revision !== $revision, 409, 'Auftrag wurde geändert. Bitte neu laden.');
            abort_if($row->state === 'running' && $row->lease_until && now()->isBefore($row->lease_until), 409, 'Auftrag wird bereits bearbeitet.');
            abort_if($row->state === 'closed' && $revision !== null, 409, 'Abgeschlossener Auftrag bleibt im Protokoll.');
            abort_unless($row->connection_hash === null || hash_equals($row->connection_hash,
                app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant) ?? ''), 409, 'Verbindung geändert. Auftrag zuerst fachlich prüfen.');
            $this->table()->where('id', $id)->update(['state' => 'running', 'attempts' => $row->attempts + 1,
                'revision' => $row->revision + 1, 'lease_token' => $token, 'lease_until' => now()->addMinutes(5)->toIso8601String(), 'updated_at' => now()->toIso8601String()]);
            $this->event($id, $actor, 'started');

            return $row;
        });
        try {
            $payload = json_decode(Crypt::decryptString($row->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
            $result = $this->handle($actor, $tenant, $row, $payload);
            $code = $this->outcome($row, $result);
            $this->finish($id, $actor, $token, $code === null ? 'succeeded' : 'attention', $code);

            return $result;
        } catch (\Throwable $error) {
            // Store only a stable classification, never provider bodies, tokens, signed URLs or personal data.
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
            $this->finish($id, $actor, $token, 'attention', in_array($status, [401, 403, 404, 409, 422, 429, 500, 502, 503, 504], true) ? 'status_'.$status : 'operation_failed');
            Log::warning('clubunio.sync.failed', ['work_item_id' => $id, 'tenant_id' => $tenant->id, 'actor_id' => $actor?->id, 'operation' => $row->operation, 'status' => $status]);
            throw $error;
        }
    }

    private function finish(int $id, ?Account $actor, string $token, string $state, ?string $code): void
    {
        DB::connection('clubunio')->transaction(function () use ($id, $actor, $token, $state, $code) {
            $count = $this->table()->where('id', $id)->where('lease_token', $token)->update([
                'state' => $state, 'error_code' => $code, 'lease_token' => null, 'lease_until' => null,
                'revision' => DB::connection('clubunio')->raw('revision + 1'), 'updated_at' => now()->toIso8601String(),
            ]);
            if ($count) {
                $this->event($id, $actor, $state, $code);
            }
        });
    }

    private function handle(?Account $actor, Tenant $tenant, object $row, array $payload): mixed
    {
        return match ($row->operation) {
            'azure_upload' => app(DocumentUploadService::class)->process($actor, $tenant, $row->object_id),
            'invoice_transfer' => app(BexioInvoiceTransferService::class)->transferNow($actor, $tenant, $row->object_id, (int) $payload['revision']),
            'invoice_action' => $this->invoiceAction($actor, $tenant, $row->object_id, $payload['operation']),
            'invoice_status', 'expense_status' => app(BexioReconciliationService::class)->previewNow($actor, $tenant, $row->operation === 'invoice_status' ? 'invoice' : 'expense', $row->object_id),
            'invoice_sync', 'expense_sync' => $this->syncDocument($actor, $tenant, $row->operation === 'invoice_sync' ? 'invoice' : 'expense', $row->object_id),
            'catalog' => app(BexioCatalogService::class)->syncNow($actor, $tenant),
            'sfv_schedule' => app(SfvPlanningService::class)->refreshNow($actor, $tenant, $payload),
            'dispatch_result' => $this->dispatchResult($actor, $tenant, $row->object_id),
            'invoice_result' => app(BexioInvoiceActionService::class)->reconcile($actor, $tenant, $row->object_id),
            'finance_result' => app(BexioFinancialActionService::class)->reconcile($actor, $tenant, $payload['kind'], $row->object_id),
            'training_review' => abort(409, 'KI-Anfrage im Trainingsplan prüfen. Kein automatischer kostenpflichtiger Neuaufruf.'),
            default => abort(422),
        };
    }

    private function syncDocument(Account $actor, Tenant $tenant, string $kind, int $id): int
    {
        $service = app(BexioReconciliationService::class);
        $check = $service->previewNow($actor, $tenant, $kind, $id);
        if (DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $check)->value('state') === 'ready') {
            $service->apply($actor, $tenant, $check);
        }

        return $check;
    }

    private function invoiceAction(Account $actor, Tenant $tenant, int $id, string $operation): void
    {
        $existing = DB::connection('clubunio')->table('clubora.bexio_invoice_actions as a')
            ->join('clubora.bexio_sandbox_dispatches as d', 'd.id', '=', 'a.dispatch_id')
            ->where('d.tenant_id', $tenant->id)->where('d.invoice_id', $id)->where('a.operation', $operation)->exists();
        if ($existing) {
            app(BexioInvoiceActionService::class)->reconcile($actor, $tenant, $id);
            $open = DB::connection('clubunio')->table('clubora.bexio_invoice_actions as a')
                ->join('clubora.bexio_sandbox_dispatches as d', 'd.id', '=', 'a.dispatch_id')
                ->where('d.tenant_id', $tenant->id)->where('d.invoice_id', $id)->where('a.operation', $operation)->where('a.state', '<>', 'accepted')->exists();
            abort_if($open, 409, 'Bexio-Aktion weiterhin unbestätigt. Keine erneute Übertragung.');
        } else {
            app(BexioInvoiceActionService::class)->executeNow($actor, $tenant, $id, $operation);
        }
    }

    private function dispatchResult(Account $actor, Tenant $tenant, int $id): void
    {
        // The existing dispatch owns the payload and remote marker. Never manufacture a replacement.
        $row = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->find($id);
        abort_unless($row, 404);
        $service = app(BexioSandboxDispatchService::class);
        if ($row->state === 'ready') {
            $service->send($actor, $tenant, $id);
        } else {
            $service->reconcile($actor, $tenant, $id);
        }
    }

    private function outcome(object $row, mixed $result): ?string
    {
        if ($row->operation === 'invoice_transfer' && $result->state !== 'accepted') {
            return 'provider_unconfirmed';
        }
        if (in_array($row->operation, ['invoice_status', 'expense_status', 'invoice_sync', 'expense_sync'], true)) {
            $state = DB::connection('clubunio')->table('clubora.bexio_checks')->where('id', $result)->value('state');

            return in_array($state, ['ready', 'unchanged', 'applied'], true) ? null : 'status_unconfirmed';
        }
        if ($row->operation === 'dispatch_result') {
            return DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('id', $row->object_id)->value('state') === 'accepted' ? null : 'provider_unconfirmed';
        }
        if (in_array($row->operation, ['invoice_action', 'invoice_result', 'finance_result'], true)) {
            $payload = json_decode(Crypt::decryptString($row->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
            $kind = $row->operation !== 'finance_result' ? 'invoice' : $payload['kind'];
            $table = $row->operation !== 'finance_result' ? 'bexio_invoice_actions' : 'bexio_financial_actions';
            $open = DB::connection('clubunio')->table('clubora.'.$table.' as a')->join('clubora.bexio_sandbox_dispatches as d', 'd.id', '=', 'a.dispatch_id')
                ->where('d.tenant_id', $row->tenant_id)->where('d.'.$kind.'_id', $row->object_id)->where('a.state', '<>', 'accepted')->exists();

            return $open ? 'provider_unconfirmed' : null;
        }

        return null;
    }

    public function close(Account $actor, Tenant $tenant, int $id, int $revision, string $note): void
    {
        $this->authorize($actor, $tenant);
        abort_unless(mb_strlen(trim($note)) >= 5 && mb_strlen($note) <= 1000, 422);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $note) {
            $row = $this->table()->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && (int) $row->revision === $revision, 409);
            abort_unless(in_array($row->state, ['attention', 'pending'], true), 409);
            if ($row->operation === 'azure_upload') {
                app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('id', $row->object_id)->where('state', 'pending')->update(['state' => 'cancelled']);
            }
            $this->table()->where('id', $id)->update(['state' => 'closed', 'revision' => $revision + 1, 'updated_at' => now()->toIso8601String()]);
            $this->event($id, $actor, 'closed_manually', null, trim($note));
        });
    }

    public function importOutstanding(Account $actor, Tenant $tenant): int
    {
        $this->authorize($actor, $tenant);
        $db = DB::connection('clubunio');
        $candidates = [];
        foreach ($db->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('state', '<>', 'accepted')->orderBy('id')->limit(500)->get() as $row) {
            $candidates[] = ['dispatch_result', $row->id, []];
        }
        foreach (['bexio_invoice_actions' => 'invoice_result', 'bexio_financial_actions' => 'finance_result'] as $table => $operation) {
            $rows = $db->table('clubora.'.$table.' as a')->join('clubora.bexio_sandbox_dispatches as d', 'd.id', '=', 'a.dispatch_id')
                ->where('d.tenant_id', $tenant->id)->where('a.state', '<>', 'accepted')->select('d.kind', 'd.invoice_id', 'd.expense_id')->distinct()->limit(500)->get();
            foreach ($rows as $row) {
                $candidates[] = [$operation, $row->{$row->kind.'_id'}, $operation === 'finance_result' ? ['kind' => $row->kind] : []];
            }
        }
        $lastChecks = $db->table('clubora.bexio_checks')->where('tenant_id', $tenant->id)
            ->selectRaw('max(id)')->groupBy('object_type', 'object_id');
        foreach ($db->table('clubora.bexio_checks')->whereIn('id', $lastChecks)->where('state', 'failed')->limit(500)->get() as $check) {
            $candidates[] = [$check->object_type.'_status', $check->object_id, []];
        }
        $existingTraining = $this->table()->where('tenant_id', $tenant->id)->where('operation', 'training_review')->pluck('object_id')->all();
        foreach ($db->table('clubora.training_plan_requests')->where('tenant_id', $tenant->id)->where('state', 'failed')->whereNotIn('id', $existingTraining)->limit(500)->get() as $request) {
            $candidates[] = ['training_review', $request->id, []];
        }

        return $this->rememberMany($actor, $tenant, $candidates);
    }

    private function rememberMany(Account $actor, Tenant $tenant, array $candidates): int
    {
        $hash = app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        $rows = [];
        foreach ($candidates as [$operation, $objectId, $payload]) {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $connection = $operation === 'training_review' ? null : $hash;
            $key = hash('sha256', $operation.':'.$objectId.':'.$connection.':'.$json);
            $rows[$key] = ['tenant_id' => $tenant->id, 'operation' => $operation, 'object_id' => $objectId,
                'deduplication_key' => $key, 'connection_hash' => $connection, 'encrypted_payload' => Crypt::encryptString($json), 'created_by' => $actor->id];
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $rows) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $existing = $this->table()->where('tenant_id', $tenant->id)->whereIn('deduplication_key', array_keys($rows))->pluck('deduplication_key')->all();
            foreach ($existing as $key) {
                unset($rows[$key]);
            }
            $knownTraining = $this->table()->where('tenant_id', $tenant->id)->where('operation', 'training_review')->pluck('object_id')->all();
            foreach ($rows as $key => $row) {
                if ($row['operation'] === 'training_review' && in_array($row['object_id'], $knownTraining)) {
                    unset($rows[$key]);
                }
            }
            foreach (array_chunk(array_values($rows), 100) as $chunk) {
                $this->table()->insert($chunk);
            }
            $events = $this->table()->where('tenant_id', $tenant->id)->whereIn('deduplication_key', array_keys($rows))->pluck('id')
                ->map(fn ($id) => ['work_item_id' => $id, 'actor_id' => $actor->id, 'event' => 'recorded'])->all();
            foreach (array_chunk($events, 100) as $chunk) {
                DB::connection('clubunio')->table('clubora.sync_work_events')->insert($chunk);
            }

            return count($rows);
        });
    }
}
