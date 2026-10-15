<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationDocumentService
{
    public function add(Account $actor, Tenant $tenant, int $id, int $revision, string $kind, UploadedFile $file): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        Validator::make(['kind' => $kind, 'file' => $file], ['kind' => 'required|in:portrait,identity',
            'file' => 'required|file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/webp'])->validate();
        $bytes = $file->get();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($kind === 'portrait' && $mime === 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'Ein Passfoto muss ein Bild sein.']);
        }
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $kind, $file, $bytes, $mime) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            $r = Registration::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id);
            if ($r->revision !== $revision || $r->status?->workflow_key === 'accepted') {
                throw ValidationException::withMessages(['revision' => 'Anmeldung geändert oder bereits abgeschlossen. Bitte erneut öffnen.']);
            }
            $db = DB::connection('clubunio');
            $query = $db->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $id)->where('active', true);
            if ($kind === 'portrait' && (clone $query)->where('kind', 'portrait')->exists()) {
                throw ValidationException::withMessages(['file' => 'Vor dem Ersetzen das bestehende Passfoto entfernen.']);
            }
            $sha = hash('sha256', $bytes);
            if ((clone $query)->where('sha256', $sha)->where('kind', $kind)->exists()) {
                return;
            }
            $folder = 'clubunio/registrations/'.$tenant->id.'/'.$id.'/';
            $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $filename = Str::uuid().'.'.$extension;
            try {
                app(RegistrationBlobStore::class)->put($folder, $filename, $bytes, $mime);
            } catch (\Throwable) {
                $reference = (string) Str::uuid();
                Log::warning('clubunio.registration.upload_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'registration_id' => $id]);
                throw ValidationException::withMessages(['file' => 'Upload fehlgeschlagen. Referenz: '.$reference]);
            }
            $document = $db->table('clubora.registration_documents')->insertGetId(['tenant_id' => $tenant->id,
                'registration_id' => $id, 'kind' => $kind, 'storage_path' => $folder, 'filename' => $filename,
                'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'sha256' => $sha, 'file_size' => strlen($bytes), 'mime_type' => $mime, 'created_by' => $actor->id]);
            $r->forceFill(['revision' => $r->revision + 1])->save();
            $this->audit($actor, $tenant, $document, $id, 'added');
        });
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
