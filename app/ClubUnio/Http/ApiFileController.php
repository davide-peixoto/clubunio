<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Services\InvoicePdfService;
use App\ClubUnio\Services\LegacyMaterialReceiptService;
use App\ClubUnio\Services\MaterialReceiptService;
use App\ClubUnio\Services\MemberDocumentService;
use Illuminate\Http\Request;

final class ApiFileController
{
    public const TYPES = [
        'invoice-pdf' => 'invoices', 'invoice-bexio-pdf' => 'invoices',
        'member-document' => 'documents', 'registration-document' => 'registrations',
        'expense-document' => 'expenses', 'material-receipt' => 'documents', 'historical-material-receipt' => 'documents',
    ];

    public function open(Request $request, string $kind, int $id)
    {
        /** @var ApiContext $c */
        $c = $request->attributes->get('clubunio.api.context');
        $module = self::TYPES[$kind] ?? abort(404);
        $c->authorize($module.':download', $module, 'download');
        abort_if(array_diff(array_keys($request->query()), ['download', 'index']), 422);
        $data = $request->validate(['download' => 'sometimes|boolean', 'index' => 'sometimes|integer|min:0|max:20']);
        $download = $request->boolean('download');
        $response = match ($kind) {
            'invoice-pdf', 'invoice-bexio-pdf' => app(InvoicePdfService::class)->open($c->actor, $c->tenant, $id, $kind === 'invoice-bexio-pdf', $download),
            'member-document' => app(MemberDocumentService::class)->open($c->actor, $c->tenant, $id, $download),
            'registration-document' => app(MemberDocumentService::class)->openRegistration($c->actor, $c->tenant, $id, $download),
            'expense-document' => app(MemberDocumentService::class)->openExpense($c->actor, $c->tenant, $id, $download, $data['index'] ?? 0),
            'material-receipt' => app(MaterialReceiptService::class)->pdf($c->actor, $c->tenant, $id, true),
            'historical-material-receipt' => app(LegacyMaterialReceiptService::class)->pdf($c->actor, $c->tenant, $id, true, false),
        };
        abort_unless($response->getStatusCode() === 200, $response->getStatusCode());

        return $response;
    }
}
