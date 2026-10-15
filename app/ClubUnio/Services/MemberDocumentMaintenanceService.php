<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MemberDocumentMaintenanceService
{
    public function add(Account $actor, Tenant $tenant, int $member, int $type, bool $portalVisible, UploadedFile $file): MemberDocument
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
        Validator::make(['file' => $file], ['file' => 'required|file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/webp'])->validate();
        if (MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $member)->where('document_type_id', $type)->where('active', true)->where('sha256', hash('sha256', $file->get()))->exists()) {
            throw ValidationException::withMessages(['file' => 'Dieses Dokument ist bereits vorhanden.']);
        }
        $uploads = app(DocumentUploadService::class);
        $id = $uploads->stage($actor, $tenant, 'member', $member, ['type' => $type, 'portal_visible' => $portalVisible], $file);
        $document = $uploads->attempt($actor, $tenant, $id);

        return MemberDocument::where('tenant_id', $tenant->id)->findOrFail($document);
    }

    public function publishUpload(Account $actor, Tenant $tenant, object $upload, array $metadata): MemberDocument
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 409);
        $existing = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $upload->object_id)
            ->where('document_type_id', $metadata['type'])->where('active', true)->where('sha256', $upload->sha256)->first();
        if ($existing) {
            return $existing;
        }
        $document = new MemberDocument;
        $document->forceFill(['tenant_id' => $tenant->id, 'member_id' => $upload->object_id, 'document_type_id' => $metadata['type'],
            'storage_path' => $upload->folder, 'filename' => $upload->filename, 'original_filename' => $metadata['original_filename'],
            'file_size' => $upload->byte_count, 'content_type' => $upload->mime_type, 'sha256' => $upload->sha256,
            'created_by' => $actor->id, 'portal_visible' => $metadata['portal_visible'], 'revision' => 1, 'active' => true, 'source_created_at' => now()])->save();
        $this->audit($actor, $document, 'uploaded');
        if ($metadata['portal_visible']) {
            $this->notify($tenant, $document);
        }

        return $document;
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
