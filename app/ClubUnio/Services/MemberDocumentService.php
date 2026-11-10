<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MemberDocumentService
{
    public function open(Account $actor, Tenant $tenant, int $id, bool $download = false)
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'documents', 'download');
        $document = MemberDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('archived_at'))->findOrFail($id);

        return $this->readResponse($actor, $tenant, $document, $download, 'member');
    }

    public function openRegistration(Account $actor, Tenant $tenant, int $id, bool $download = false)
    {
        if (app(ModulePermissionService::class)->isProfile($actor, $tenant)) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'registrations', 'download');
        }
        $document = DB::connection('clubunio')->table('clubunio.registration_documents')->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('registration_id', app(RegistrationReviewService::class)->visible($actor, $tenant)->select('clubunio.registrations.id'))->where('id', $id)->first();
        abort_unless($document, 404);

        return $this->readResponse($actor, $tenant, $document, $download, 'registration');
    }

    public function openPortal(Account $actor, Tenant $tenant, int $id, bool $download = false)
    {
        $document = MemberDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('portal_visible', true)
            ->whereIn('member_id', app(MemberAccessService::class)->represented($actor->fresh(), $tenant)->select('clubunio.members.id'))->findOrFail($id);
        abort_unless(app(MemberAccessService::class)->canEnter($actor->fresh(), $tenant), 403);

        return $this->readResponse($actor, $tenant, $document, $download, 'member');
    }

    private function readResponse(Account $actor, Tenant $tenant, object $document, bool $download, string $objectType)
    {
        if ($path = TenantDocumentStorage::parse($document->storage_path)) {
            $registration = $path['namespace'] === 'registrations' && (($objectType === 'member' && $document->registration_document_id)
                || ($objectType === 'registration' && $path['object'] === (string) $document->registration_id));
            abort_unless($path['tenant'] === (string) $tenant->id && ($registration
                || ($objectType === 'member' && $path['namespace'] === 'members' && $path['object'] === (string) $document->member_id)
                || ($objectType === 'expense' && $path['namespace'] === 'expenses' && $path['object'] === (string) $document->id)), 404);
        } elseif (str_starts_with($document->storage_path, 'clubunio/')) {
            $registration = str_starts_with($document->storage_path, 'clubunio/registrations/'.$tenant->id.'/');
            $member = $objectType === 'member' && $document->storage_path === 'clubunio/members/'.$tenant->id.'/'.$document->member_id.'/';
            abort_unless($registration || $member, 404);
        }
        try {
            $bytes = app(TenantDocumentStorage::class)->read($tenant, $document->storage_path, $document->filename);
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            $status = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : $error->getCode();
            $status = $status === 404 ? 404 : 503;
            Log::warning('clubunio.document.read_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'document_id' => $document->id, 'object_type' => $objectType, 'actor_id' => $actor->id, 'code' => $status]);
            abort($status, __('Dokument nicht verfügbar. Referenz: :value1', ['value1' => $reference]));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $inline = ! $download && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
        $name = basename(str_replace('\\', '/', ($document->original_filename ?? null) ?: $document->filename));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?: 'Dokument';
        $disposition = HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $name, 'Dokument');

        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Length' => strlen($bytes),
            'Content-Disposition' => $disposition, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox"]);
    }

    public function openExpense(Account $actor, Tenant $tenant, int $id, bool $download = false, int $index = 0)
    {
        if (app(ModulePermissionService::class)->isProfile($actor, $tenant)) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'expenses', 'download');
        }
        $line = ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('expense_id', app(ExpenseService::class)->visible($actor, $tenant)->select('clubunio.expenses.id'))->findOrFail($id);
        $files = array_values(array_filter(array_map('trim', explode(',', (string) $line->storage_reference))));
        abort_unless($index >= 0 && isset($files[$index]), 404);
        [$folder, $filename] = app(TenantDocumentStorage::class)->expensePath($tenant, $files[$index], (int) $line->id);
        $document = (object) ['id' => $id, 'filename' => $filename, 'storage_path' => $folder, 'original_filename' => $line->filename ?: $filename];

        return $this->readResponse($actor, $tenant, $document, $download, 'expense');
    }
}
