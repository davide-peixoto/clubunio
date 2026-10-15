<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberDocumentMaintenanceService
{
    public function add(Account $actor, Tenant $tenant, int $member, int $type, bool $portalVisible, UploadedFile $file): MemberDocument
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        abort_unless($tenant->slug === config('clubunio-integrations.azure.tenant_slug'), 403);
        Validator::make(['file' => $file], ['file' => 'required|file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/webp'])->validate();
        $bytes = $file->get();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime])) {
            throw ValidationException::withMessages(['file' => 'Nicht unterstützter Dateityp.']);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $member, $type, $portalVisible, $file, $bytes, $mime, $extensions) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($member);
            DocumentType::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($type);
            $sha = hash('sha256', $bytes);
            if (MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $member)->where('document_type_id', $type)->where('active', true)->where('sha256', $sha)->exists()) {
                throw ValidationException::withMessages(['file' => 'Dieses Dokument ist bereits vorhanden.']);
            }
            $folder = 'clubunio/members/'.$tenant->id.'/'.$member.'/';
            $filename = Str::uuid().'.'.$extensions[$mime];
            try {
                app(RegistrationBlobStore::class)->put($folder, $filename, $bytes, $mime);
            } catch (\Throwable) {
                $reference = (string) Str::uuid();
                Log::warning('clubunio.member_document.upload_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'member_id' => $member, 'actor_id' => $actor->id]);
                throw ValidationException::withMessages(['file' => 'Upload fehlgeschlagen. Referenz: '.$reference]);
            }
            $document = new MemberDocument;
            $document->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member, 'document_type_id' => $type,
                'storage_path' => $folder, 'filename' => $filename,
                'original_filename' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 255),
                'file_size' => strlen($bytes), 'content_type' => $mime, 'sha256' => $sha, 'created_by' => $actor->id,
                'portal_visible' => $portalVisible, 'revision' => 1, 'active' => true, 'source_created_at' => now()])->save();
            $this->audit($actor, $document, 'uploaded');
            if ($portalVisible) {
                $this->notify($tenant, $document);
            }

            return $document;
        });
    }

    public function update(Account $actor, Tenant $tenant, int $id, int $member, int $revision, bool $active, bool $portalVisible): void
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $member, $revision, $active, $portalVisible) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($member);
            $document = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $member)->lockForUpdate()->findOrFail($id);
            if ($document->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Dokument wurde inzwischen geändert. Bitte neu öffnen.']);
            }
            $newlyVisible = $active && $portalVisible && (! $document->active || ! $document->portal_visible);
            $document->forceFill(['active' => $active, 'portal_visible' => $portalVisible, 'revision' => $revision + 1])->save();
            $this->audit($actor, $document, 'updated');
            if ($newlyVisible) {
                $this->notify($tenant, $document);
            }
        });
    }

    private function audit(Account $actor, MemberDocument $document, string $operation): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $document->tenant_id, 'actor_id' => $actor->id,
            'object_type' => 'member_documents', 'object_id' => $document->id,
            'after_value' => json_encode(['operation' => $operation, 'member_id' => $document->member_id, 'revision' => $document->revision,
                'active' => $document->active, 'portal_visible' => $document->portal_visible], JSON_THROW_ON_ERROR)]);
    }

    private function notify(Tenant $tenant, MemberDocument $document): void
    {
        $message = app(MessageTemplateService::class)->render($tenant, 'member.document.available', 'push', ['verein' => $tenant->name]);
        if (! $message) {
            return;
        }
        $db = DB::connection('clubunio');
        $accounts = Account::where('active', true)->whereIn('id', $db->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('member_id', $document->member_id)->where('state', 'approved')->select('account_id'))->get();
        foreach ($accounts as $account) {
            if (app(MemberAccessService::class)->canEnter($account, $tenant) && app(MemberAccessService::class)->represented($account, $tenant)->whereKey($document->member_id)->exists()) {
                $db->table('clubora.notifications')->insert(['tenant_id' => $tenant->id, 'account_id' => $account->id,
                    'title' => $message['title'], 'body' => $message['body'], 'object_type' => 'member_document', 'object_id' => $document->id]);
            }
        }
    }
}
