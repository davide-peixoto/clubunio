<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\LegacyMaterialReceipt;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class LegacyMaterialReceiptService
{
    public function visible(Account $actor, Tenant $tenant, bool $administration = false)
    {
        $actor = $actor->fresh();
        $query = LegacyMaterialReceipt::where('tenant_id', $tenant->id);
        if ($administration) {
            abort_unless($actor?->canAccessTenant($tenant), 403);

            return $query;
        }
        $access = app(MemberAccessService::class);
        abort_unless($actor && $access->canEnter($actor, $tenant), 403);

        return $query->whereIn('member_id', $access->represented($actor, $tenant)->select('clubora.members.id'));
    }

    public function import(Account $actor, Tenant $tenant): array
    {
        abort_unless($tenant->slug === 'sv-sissach' && $actor->fresh()?->canAccessTenant($tenant), 403);
        $db = DB::connection('clubunio');
        abort_unless($db->getDatabaseName() === 'db_acdf8d_dclubora', 409);
        $counts = ['imported' => 0, 'unchanged' => 0, 'blocked' => 0];
        foreach ($db->table('legacy_prod.MitgliederQuittungen')->orderBy('QuittungID')->get() as $source) {
            $hash = hash('sha256', json_encode((array) $source, JSON_THROW_ON_ERROR));
            $old = LegacyMaterialReceipt::where('tenant_id', $tenant->id)->where('source_id', $source->QuittungID)->first();
            if ($old) {
                $counts[hash_equals($old->source_hash, $hash) ? 'unchanged' : 'blocked']++;

                continue;
            }
            $member = Member::where('tenant_id', $tenant->id)->where('legacy_id', $source->MitgliederID)->sole();
            $documents = [];
            foreach (['original' => [$source->OriginalAnhangID, $source->OriginalSha256], 'signed' => [$source->SigniertAnhangID, $source->SigniertSha256]] as $kind => [$legacyId, $sha]) {
                if (! $legacyId && $kind === 'signed') {
                    continue;
                }
                $document = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('legacy_id', $legacyId)->where('active', true)->sole();
                abort_unless(preg_match('/^[a-f0-9]{64}$/iD', (string) $sha), 409, 'Historischer Dokumenthash fehlt.');
                $bytes = app(AzureDocumentReader::class)->read($document->storage_path, $document->filename);
                abort_unless(str_starts_with($bytes, '%PDF-') && hash_equals(strtolower($sha), hash('sha256', $bytes)), 409, 'Historisches Dokument stimmt nicht mit dem Nachweis überein.');
                $documents[$kind] = ['id' => $document->id, 'hash' => strtolower($sha)];
            }
            abort_if($source->Status === 'UNTERSCHRIEBEN' && (! isset($documents['signed']) || ! $source->SigniertAm || ! $source->SigniertName), 409);
            $db->transaction(function () use ($db, $actor, $tenant, $source, $hash, $member, $documents, &$counts) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
                $current = $db->table('legacy_prod.MitgliederQuittungen')->where('QuittungID', $source->QuittungID)->first();
                abort_unless($current && hash_equals($hash, hash('sha256', json_encode((array) $current, JSON_THROW_ON_ERROR))), 409);
                if (LegacyMaterialReceipt::where('tenant_id', $tenant->id)->where('source_id', $source->QuittungID)->exists()) {
                    $counts['unchanged']++;

                    return;
                }
                $record = new LegacyMaterialReceipt;
                $record->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'source_id' => $source->QuittungID, 'source_hash' => $hash,
                    'title' => $source->Titel, 'source_state' => $source->Status, 'signed_name' => $source->SigniertName, 'signed_at' => $source->SigniertAm,
                    'original_document_id' => $documents['original']['id'], 'signed_document_id' => $documents['signed']['id'] ?? null,
                    'original_sha256' => $documents['original']['hash'], 'signed_sha256' => $documents['signed']['hash'] ?? null, 'imported_by' => $actor->id])->save();
                $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'legacy_material_receipts', 'object_id' => $record->id,
                    'after_value' => json_encode(['action' => 'archive_import', 'source_id' => $record->source_id, 'source_hash' => $hash], JSON_THROW_ON_ERROR)]);
                $counts['imported']++;
            });
        }

        return $counts;
    }

    public function pdf(Account $actor, Tenant $tenant, int $id, bool $administration = false, bool $original = false)
    {
        $record = $this->visible($actor, $tenant, $administration)->findOrFail($id);
        $documentId = $original ? $record->original_document_id : ($record->signed_document_id ?: $record->original_document_id);
        $sha = $original ? $record->original_sha256 : ($record->signed_sha256 ?: $record->original_sha256);
        $document = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $record->member_id)->where('active', true)->findOrFail($documentId);
        $bytes = app(AzureDocumentReader::class)->read($document->storage_path, $document->filename);
        abort_unless(str_starts_with($bytes, '%PDF-') && hash_equals($sha, hash('sha256', $bytes)), 409);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Altquittung-'.$id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN']);
    }
}
