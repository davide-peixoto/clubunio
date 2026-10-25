<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiIdempotency;
use App\ClubUnio\Services\DocumentUploadService;
use App\ClubUnio\Services\SyncWorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ApiUploadController
{
    public const TYPES = ['member' => 'documents', 'registration' => 'registrations', 'expense' => 'expenses'];

    public function stage(Request $request, string $kind, int $id)
    {
        $c = $request->attributes->get('clubunio.api.context');
        $this->authorize($c, $kind);
        abort_if($request->query(), 422);
        $rules = match ($kind) {
            'member' => ['type' => 'required|integer|min:1', 'portal_visible' => 'required|boolean'],
            'registration' => ['kind' => 'required|in:portrait,identity'],
            'expense' => ['revision' => 'required|integer|min:1', 'expense_revision' => 'required|integer|min:1'],
        };
        abort_if(array_diff(array_keys($request->all()), [...array_keys($rules), 'file']), 422);
        $data = Validator::make($request->all(), [...$rules, 'file' => 'required|file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/webp'])->validate();
        $file = $data['file'];
        abort_unless(mb_check_encoding($file->getClientOriginalName(), 'UTF-8'), 422);
        unset($data['file']);
        if ($kind === 'member') {
            $data['portal_visible'] = $request->boolean('portal_visible');
            if ($data['portal_visible']) {
                $c->authorize('documents:send', 'documents', 'send');
            }
        }

        return app(ApiIdempotency::class)->execute($c, $request, function () use ($c, $kind, $id, $data, $file) {
            $upload = app(DocumentUploadService::class)->stage($c->actor, $c->tenant, $kind, $id, $data, $file);

            return response()->json(['data' => $this->status($c, $upload)], 202);
        }, ['metadata' => $data, 'sha256' => hash_file('sha256', $file->getRealPath()), 'filename' => $file->getClientOriginalName()]);
    }

    public function show(Request $request, int $id)
    {
        abort_if($request->query(), 422);

        return response()->json(['data' => $this->status($request->attributes->get('clubunio.api.context'), $id)]);
    }

    public function process(Request $request, int $id)
    {
        abort_if($request->query() || array_diff(array_keys($request->all()), []), 422);
        $c = $request->attributes->get('clubunio.api.context');
        $before = $this->status($c, $id);
        if ($before['state'] !== 'published') {
            $row = app(DocumentUploadService::class)->table()->where('tenant_id', $c->tenant->id)->where('id', $id)->firstOrFail();
            $metadata = json_decode(Crypt::decryptString($row->encrypted_metadata), true, flags: JSON_THROW_ON_ERROR);
            if ($row->kind === 'member' && ($metadata['portal_visible'] ?? false)) {
                $c->authorize('documents:send', 'documents', 'send');
            }
            // This service has its own persisted provider intent and reconciliation. Never wrap network I/O in the API transaction.
            app(SyncWorkService::class)->runDocument($c->actor, $c->tenant, $id);
        }

        return response()->json(['data' => $this->status($c, $id)]);
    }

    private function authorize(ApiContext $c, string $kind): void
    {
        $module = self::TYPES[$kind] ?? abort(404);
        $c->authorize($module.':upload', $module, 'upload');
    }

    private function status(ApiContext $c, int $id): array
    {
        $row = DB::connection('clubunio')->table('clubora.document_uploads')->where('tenant_id', $c->tenant->id)
            ->where('created_by', $c->actor->id)->where('id', $id)->firstOrFail();
        $this->authorize($c, $row->kind);

        return ['id' => $row->id, 'kind' => $row->kind, 'object_id' => $row->object_id,
            'state' => $row->state, 'result_id' => $row->result_id];
    }
}
