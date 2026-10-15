<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationDocumentService
{
    public function add(Account $actor, Tenant $tenant, int $id, int $revision, string $kind, UploadedFile $file): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);

        $this->store($actor, $tenant, $id, $revision, $kind, $file);
    }

    public function addPublic(Tenant $tenant, Registration $registration, string $requestId, string $kind, UploadedFile $file): void
    {
        app(PublicWebService::class)->authorize($tenant, 'registration');
        abort_unless(DB::connection('clubunio')->table('clubora.public_registration_submissions')->where('tenant_id', $tenant->id)
            ->where('registration_id', $registration->id)->where('request_id', $requestId)->exists(), 403);
        app(DocumentUploadService::class)->stage(null, $tenant, 'registration', $registration->id, ['kind' => $kind], $file, $requestId);
    }

    private function store(?Account $actor, Tenant $tenant, int $id, int $revision, string $kind, UploadedFile $file): void
    {
        $upload = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $kind, $file) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $r = Registration::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id);
            if ($r->revision !== $revision || $r->status?->workflow_key === 'accepted') {
                throw ValidationException::withMessages(['revision' => 'Anmeldung geändert oder bereits abgeschlossen. Bitte erneut öffnen.']);
            }

            return app(DocumentUploadService::class)->stage($actor, $tenant, 'registration', $id, compact('kind'), $file);
        });
        app(DocumentUploadService::class)->attempt($actor, $tenant, $upload);
    }

    public function publishUpload(?Account $actor, Tenant $tenant, object $upload, array $metadata): int
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 409);
        $db = DB::connection('clubunio');
        $query = $db->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $upload->object_id)->where('active', true)
            ->where('kind', $metadata['kind'])->where('sha256', $upload->sha256);
        if ($existing = $query->value('id')) {
            return (int) $existing;
        }
        $document = $db->table('clubora.registration_documents')->insertGetId(['tenant_id' => $tenant->id,
            'registration_id' => $upload->object_id, 'kind' => $metadata['kind'], 'storage_path' => $upload->folder,
            'filename' => $upload->filename, 'original_filename' => $metadata['original_filename'], 'sha256' => $upload->sha256,
            'file_size' => $upload->byte_count, 'mime_type' => $upload->mime_type, 'created_by' => $upload->created_by]);
        Registration::where('tenant_id', $tenant->id)->whereKey($upload->object_id)->increment('revision');
        if ($actor) {
            $this->audit($actor, $tenant, $document, $upload->object_id, 'added');
        }

        return $document;
    }

    public function remove(Account $actor, Tenant $tenant, int $id, int $document, int $revision): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $document, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $r = Registration::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id);
            if ($r->revision !== $revision || $r->status?->workflow_key === 'accepted') {
                throw ValidationException::withMessages(['revision' => 'Anmeldung geändert oder bereits abgeschlossen.']);
            }
            $changed = DB::connection('clubunio')->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $id)
                ->where('id', $document)->where('active', true)->update(['active' => false]);
            abort_unless($changed, 404);
            $r->forceFill(['revision' => $r->revision + 1])->save();
            $this->audit($actor, $tenant, $document, $id, 'removed');
        });
    }

    private function audit(Account $actor, Tenant $tenant, int $document, int $registration, string $operation): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'registration_documents', 'object_id' => $document,
            'after_value' => json_encode(['registration_id' => $registration, 'operation' => $operation], JSON_THROW_ON_ERROR)]);
    }
}
