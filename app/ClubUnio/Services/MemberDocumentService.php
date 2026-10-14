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
        abort_unless($actor->canAccessTenant($tenant), 403);
        $document = MemberDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('archived_at'))->findOrFail($id);

        return $this->readResponse($actor, $tenant, $document, $download, 'member');
    }

    public function openRegistration(Account $actor, Tenant $tenant, int $id, bool $download = false)
    {
        $document = DB::connection('clubunio')->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('registration_id', app(RegistrationReviewService::class)->visible($actor, $tenant)->select('clubora.registrations.id'))->where('id', $id)->first();
        abort_unless($document, 404);

        return $this->readResponse($actor, $tenant, $document, $download, 'registration');
    }

    private function readResponse(Account $actor, Tenant $tenant, object $document, bool $download, string $objectType)
    {
        abort_unless($tenant->slug === config('clubunio-integrations.azure.tenant_slug'), 404);
        try {
            $bytes = app(AzureDocumentReader::class)->read($document->storage_path, $document->filename);
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            Log::warning('clubunio.document.read_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'document_id' => $document->id, 'object_type' => $objectType, 'actor_id' => $actor->id, 'code' => $error->getCode() === 404 ? 404 : 503]);
            abort($error->getCode() === 404 ? 404 : 503, 'Dokument nicht verfügbar. Referenz: '.$reference);
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
        $line = ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('expense_id', app(ExpenseService::class)->visible($actor, $tenant)->select('clubora.expenses.id'))->findOrFail($id);
        $files = array_values(array_filter(array_map('trim', explode(',', (string) $line->storage_reference))));
        abort_unless($index >= 0 && isset($files[$index]), 404);
        $document = (object) ['id' => $id, 'filename' => $files[$index], 'storage_path' => 'azure.blob://files/', 'original_filename' => $line->filename ?: $files[$index]];

        return $this->readResponse($actor, $tenant, $document, $download, 'expense');
    }
}
