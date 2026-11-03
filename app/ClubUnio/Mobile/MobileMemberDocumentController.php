<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileMemberDocumentController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileMemberDocumentReady::class]; }
    private function service() { return app(MobileMemberDocuments::class); }
    public function index(Request $r) { return response()->json(['data' => $this->service()->index($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function types(Request $r) { return response()->json(['data' => $this->service()->types($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function members(Request $r) { return response()->json(['data' => $this->service()->members($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function show(Request $r) { return $this->view($r, 'receipt', (int) $r->route('receipt')); }
    public function showFile(Request $r) { return $this->view($r, 'file', (int) $r->route('document')); }
    public function showArchive(Request $r) { return $this->view($r, 'archive', (int) $r->route('archive')); }
    private function view(Request $r, string $source, int $id) { return response()->json(['data' => ['success' => true, 'Dokument' => $this->service()->view($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), $id, $source)]]); }
    public function pdf(Request $r) { return $this->download($r, 'receipt', (int) $r->route('receipt')); }
    public function filePdf(Request $r) { return $this->download($r, 'file', (int) $r->route('document')); }
    public function archivePdf(Request $r) { return $this->download($r, 'archive', (int) $r->route('archive')); }
    private function download(Request $r, string $source, int $id) {
        $d = $r->validate(['original' => 'nullable|boolean']);
        return $this->service()->pdf($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), $id, (bool) ($d['original'] ?? false), $source);
    }
    public function upload(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $r->validate(['file' => 'required|file|max:10240']);
            return ['success' => true, 'Dokument' => $this->service()->upload($actor, $tenant, $r->except('file'), $r->file('file'))];
        });
    }
    public function sign(Request $r) {
        abort_if(strlen($r->getContent()) > 200_000, 422, 'Unterschrift ist zu umfangreich.');
        return $this->write($r, fn ($actor, $tenant) => ['success' => true, 'Dokument' => $this->service()->sign($actor, $tenant, (int) $r->route('receipt'), $r->all())]);
    }
    public function signArchive(Request $r) {
        abort_if(strlen($r->getContent()) > 200_000, 422, 'Unterschrift ist zu umfangreich.');
        return $this->write($r, fn ($actor, $tenant) => ['success' => true, 'Dokument' => $this->service()->signArchive($actor, $tenant, (int) $r->route('archive'), $r->all())]);
    }
    private function write(Request $r, callable $operation) {
        return DB::connection('clubunio')->transaction(function () use ($r, $operation) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r); app(MobileAccess::class)->enter($actor, $tenant);
            return response()->json(['data' => $operation($actor, $tenant)]);
        });
    }
}
