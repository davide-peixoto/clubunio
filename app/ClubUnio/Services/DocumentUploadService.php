<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentUploadService
{
    public function table()
    {
        return DB::connection('clubunio')->table('clubora.document_uploads');
    }

    public function stage(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, UploadedFile $file, ?string $publicRequest = null): int
    {
        abort_unless($tenant->slug === config('clubunio-integrations.azure.tenant_slug'), 403);
        $types = $kind === 'expense' ? 'application/pdf,image/jpeg,image/png' : 'application/pdf,image/jpeg,image/png,image/webp';
        Validator::make(['file' => $file], ['file' => 'required|file|max:20480|mimetypes:'.$types])->validate();
        $bytes = $file->get();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        abort_unless($extension && strlen($bytes) > 0 && strlen($bytes) <= AzureDocumentReader::MAX_BYTES, 422);
        if ($kind === 'registration' && ($metadata['kind'] ?? '') === 'portrait' && $mime === 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Ein Passfoto muss ein Bild sein.']);
        }
        $metadata['original_filename'] = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255);
        $metadata['storage_fingerprint'] = $this->storageFingerprint();
        $sha = hash('sha256', $bytes);
        // Public and staff uploads never share an authority grant or retry identity.
        $key = hash('sha256', json_encode([$kind, $id, $actor?->id, $publicRequest, $sha, array_diff_key($metadata, ['original_filename' => true])], JSON_THROW_ON_ERROR));

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $kind, $id, $metadata, $bytes, $mime, $extension, $sha, $key, $publicRequest) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSource($actor, $tenant, $kind, $id, $metadata, $publicRequest);
            if ($kind === 'expense' && ($published = $this->table()->where('tenant_id', $tenant->id)->where('kind', $kind)->where('object_id', $id)
                ->where('sha256', $sha)->where('state', 'published')->value('id'))) {
                return (int) $published;
            }
            $existing = $this->table()->where('tenant_id', $tenant->id)->where('deduplication_key', $key)->first(['id', 'state']);
            if ($existing) {
                abort_if($existing->state === 'cancelled', 409, 'Dieser Upload wurde verworfen. Bitte Quelldaten neu prüfen.');

                return (int) $existing->id;
            }
            if ($kind === 'expense') {
                $reference = (string) ExpenseLine::where('tenant_id', $tenant->id)->findOrFail($id)->storage_reference;
                $publishedCount = count(array_filter(array_map('trim', explode(',', $reference))));
                $pendingCount = $this->table()->where('tenant_id', $tenant->id)->where('kind', 'expense')->where('object_id', $id)->where('state', 'pending')->count();
                if ($publishedCount + $pendingCount >= 10) {
                    throw ValidationException::withMessages(['file' => 'Maximal zehn Anhänge pro Position, inklusive offener Übertragungen.']);
                }
            }
            if ($kind === 'registration' && $metadata['kind'] === 'portrait' && $this->table()->where('tenant_id', $tenant->id)
                ->where('kind', $kind)->where('object_id', $id)->where('state', 'pending')
                ->get(['encrypted_metadata'])->contains(fn ($u) => json_decode(Crypt::decryptString($u->encrypted_metadata), true)['kind'] === 'portrait')) {
                throw ValidationException::withMessages(['file' => 'Ein Passfoto wartet bereits auf die Übertragung. Bitte Synchronisation prüfen.']);
            }
            $held = $this->table()->where('tenant_id', $tenant->id)->whereNotNull('encrypted_bytes');
            if ((clone $held)->count() >= 100 || (int) (clone $held)->sum('byte_count') + strlen($bytes) > 100 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => 'Der geschützte Upload-Zwischenspeicher ist voll. Bitte Synchronisation prüfen.']);
            }
            $namespace = ['member' => 'members', 'registration' => 'registrations', 'expense' => 'expenses'][$kind];
            $upload = $this->table()->insertGetId(['tenant_id' => $tenant->id, 'kind' => $kind, 'object_id' => $id,
                'deduplication_key' => $key, 'sha256' => $sha, 'byte_count' => strlen($bytes), 'mime_type' => $mime,
                'folder' => 'clubunio/'.$namespace.'/'.$tenant->id.'/'.$id.'/', 'filename' => Str::uuid().'.'.$extension,
                'encrypted_metadata' => Crypt::encryptString(json_encode($metadata, JSON_THROW_ON_ERROR)),
                'encrypted_bytes' => Crypt::encryptString($bytes), 'created_by' => $actor?->id, 'public_request_id' => $publicRequest]);
            app(SyncWorkService::class)->rememberDocument($tenant, $upload);

            return $upload;
        });
    }

    public function authorizeSource(?Account $actor, Tenant $tenant, string $kind, int $id, array $metadata, ?string $publicRequest): void
    {
        if ($kind === 'registration') {
            if ($actor) {
                abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            } else {
                abort_unless($publicRequest && DB::connection('clubunio')->table('clubora.public_registration_submissions')
                    ->where('tenant_id', $tenant->id)->where('registration_id', $id)->where('request_id', $publicRequest)->exists(), 403);
            }
            $r = Registration::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($id);
            abort_if($r->status?->workflow_key === 'accepted', 409, 'Anmeldung ist bereits abgeschlossen.');
            abort_unless(in_array($metadata['kind'] ?? '', ['portrait', 'identity'], true), 422);
            if ($metadata['kind'] === 'portrait' && DB::connection('clubunio')->table('clubora.registration_documents')
                ->where('tenant_id', $tenant->id)->where('registration_id', $id)->where('active', true)->where('kind', 'portrait')->exists()) {
                throw ValidationException::withMessages(['file' => 'Vor dem Ersetzen das bestehende Passfoto entfernen.']);
            }
        } elseif ($kind === 'member') {
            abort_unless($actor?->fresh()?->canAccessTenant($tenant), 403);
            Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->findOrFail($id);
            DocumentType::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($metadata['type']);
        } elseif ($kind === 'expense') {
            abort_unless($actor?->fresh()?->active, 403);
            $line = ExpenseLine::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($id);
            $expense = app(ExpenseService::class)->visible($actor->fresh(), $tenant)->findOrFail($line->expense_id);
            abort_unless(app(ExpenseService::class)->editable($actor->fresh(), $tenant, $expense), 403);
            abort_unless((int) $line->revision === (int) $metadata['revision'] && (int) $expense->revision === (int) $metadata['expense_revision'], 409, 'Spesenposition wurde geändert. Upload zuerst prüfen.');
        } else {
            abort(422);
        }
    }

    public function process(?Account $executor, Tenant $tenant, int $id): int
    {
        $upload = $this->table()->where('tenant_id', $tenant->id)->find($id);
        abort_unless($upload, 404);
        if ($executor) {
            abort_unless($executor->fresh()?->active, 403);
            abort_unless((int) $upload->created_by === (int) $executor->id || BexioConnectionService::canManage($executor->fresh(), $tenant), 403);
        } else {
            abort_unless($upload->created_by === null && $upload->public_request_id, 403);
        }
        if ($upload->state === 'published') {
            return (int) $upload->result_id;
        }
        abort_unless($upload->state === 'pending', 409);
        $actor = $upload->created_by ? Account::findOrFail($upload->created_by) : null;
        $metadata = json_decode(Crypt::decryptString($upload->encrypted_metadata), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($metadata['storage_fingerprint'], $this->storageFingerprint()), 409, 'Azure-Ziel wurde geändert. Upload zuerst prüfen.');
        $this->authorizeSource($actor, $tenant, $upload->kind, $upload->object_id, $metadata, $upload->public_request_id);
        $bytes = Crypt::decryptString($upload->encrypted_bytes);
        abort_unless(strlen($bytes) === (int) $upload->byte_count && hash_equals($upload->sha256, hash('sha256', $bytes)), 409, 'Dateiprüfsumme stimmt nicht überein.');
        $found = false;
        if ($upload->provider_started_at) {
            try {
                $stored = app(AzureDocumentReader::class)->read($upload->folder, $upload->filename);
                abort_unless(strlen($stored) === (int) $upload->byte_count && hash_equals($upload->sha256, hash('sha256', $stored)), 409, 'Datei im Zielsystem stimmt nicht überein.');
                $found = true;
            } catch (\Throwable $error) {
                if ($error->getCode() !== 404) {
                    throw $error;
                }
            }
        }
        if (! $found) {
            $this->table()->where('id', $id)->where('state', 'pending')->update(['provider_started_at' => now()->toIso8601String()]);
            app(RegistrationBlobStore::class)->put($upload->folder, $upload->filename, $bytes, $upload->mime_type);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $upload, $metadata) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $fresh = $this->table()->where('id', $upload->id)->lockForUpdate()->firstOrFail(['id', 'state', 'result_id']);
            if ($fresh->state === 'published') {
                return (int) $fresh->result_id;
            }
            abort_unless($fresh->state === 'pending', 409);
            if ($upload->kind === 'expense') {
                $expenseId = ExpenseLine::where('tenant_id', $tenant->id)->whereKey($upload->object_id)->value('expense_id');
                Expense::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($expenseId);
                ExpenseLine::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($upload->object_id);
            }
            $this->authorizeSource($actor, $tenant, $upload->kind, $upload->object_id, $metadata, $upload->public_request_id);
            $id = match ($upload->kind) {
                'member' => app(MemberDocumentMaintenanceService::class)->publishUpload($actor, $tenant, $upload, $metadata)->id,
                'registration' => app(RegistrationDocumentService::class)->publishUpload($actor, $tenant, $upload, $metadata),
                'expense' => app(ExpenseDocumentService::class)->publishUpload($actor, $tenant, $upload, $metadata),
            };
            $this->table()->where('id', $upload->id)->update(['state' => 'published', 'result_id' => $id,
                'encrypted_bytes' => null, 'published_at' => now()->toIso8601String()]);

            return $id;
        });
    }

    public function attempt(?Account $actor, Tenant $tenant, int $id): int
    {
        try {
            return app(SyncWorkService::class)->runDocument($actor, $tenant, $id);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Datei geschützt gespeichert; Azure-Übertragung noch offen. Bitte unter Synchronisation prüfen. Referenz: Upload #'.$id]);
        }
    }

    private function storageFingerprint(): string
    {
        return hash('sha256', json_encode(array_intersect_key(config('clubunio-integrations.azure'), array_flip(['account', 'container', 'tenant_slug'])), JSON_THROW_ON_ERROR));
    }
}
