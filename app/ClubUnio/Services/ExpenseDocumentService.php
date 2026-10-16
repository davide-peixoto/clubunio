<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExpenseDocumentService
{
    public function add(Account $actor, Tenant $tenant, int $lineId, int $revision, int $expenseRevision, UploadedFile $file): int
    {
        $line = ExpenseLine::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($lineId);
        abort_unless(app(ExpenseService::class)->editable($actor, $tenant, $line->expense, 'upload'), 403);
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png'])->validate();
        $uploads = app(DocumentUploadService::class);
        $id = $uploads->stage($actor, $tenant, 'expense', $lineId, ['revision' => $revision, 'expense_revision' => $expenseRevision], $file);

        return $uploads->attempt($actor, $tenant, $id);
    }

    public function publishUpload(Account $actor, Tenant $tenant, object $upload, array $metadata): int
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 409);
        $line = ExpenseLine::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($upload->object_id);
        abort_unless(app(ExpenseService::class)->editable($actor, $tenant, $line->expense, 'upload'), 403);
        $files = array_values(array_filter(array_map('trim', explode(',', (string) $line->storage_reference))));
        $reference = $upload->folder.$upload->filename;
        if (! in_array($reference, $files, true)) {
            abort_if(count($files) >= 10, 422, 'Maximal zehn Anhänge pro Position.');
            $files[] = $reference;
            $line->forceFill(['storage_reference' => implode(',', $files), 'filename' => $line->filename ?: $metadata['original_filename'], 'revision' => $line->revision + 1])->save();
            $line->expense()->increment('revision');
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'expense_lines', 'object_id' => $line->id, 'after_value' => json_encode(['action' => 'document_uploaded', 'upload_id' => $upload->id, 'sha256' => $upload->sha256], JSON_THROW_ON_ERROR)]);
        }

        return (int) $line->id;
    }
}
