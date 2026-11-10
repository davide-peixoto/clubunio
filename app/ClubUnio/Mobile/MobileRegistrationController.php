<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{DocumentUploadService, MemberDocumentService, ModulePermissionService, RegistrationDocumentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileRegistrationController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileRegistrationReady::class]; }
    private function data(): MobileRegistrationData { return app(MobileRegistrationData::class); }
    public function index(Request $r) { return response()->json(['data' => $this->data()->index($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]); }
    public function options(Request $r) {
        $d = $r->validate(['registration' => 'nullable|integer|min:1']);
        return response()->json(['data' => $this->data()->options($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), $d['registration'] ?? null)]);
    }
    public function show(Request $r) { return response()->json(['data' => ['Registration' => $this->data()->details($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('registration'))]]); }
    public function members(Request $r) { return response()->json(['data' => $this->data()->candidates($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('registration'))]); }
    public function create(Request $r) { return $this->write($r, fn ($actor, $tenant) => app(MobileRegistrationCommands::class)->intake($actor, $tenant, null, $r->all())); }
    public function intake(Request $r) { return $this->write($r, fn ($actor, $tenant) => app(MobileRegistrationCommands::class)->intake($actor, $tenant, (int) $r->route('registration'), $r->all())); }
    public function review(Request $r) { return $this->write($r, fn ($actor, $tenant) => app(MobileRegistrationCommands::class)->review($actor, $tenant, (int) $r->route('registration'), $r->all())); }
    public function upload(Request $r) {
        abort_unless(config('clubunio-mobile.uploads_enabled'), 503);
        $upload = $this->guarded($r, function ($actor, $tenant) use ($r) {
            $d = $r->validate(['Revision' => 'required|integer|min:1', 'Kind' => 'required|in:portrait,identity', 'file' => 'required|file|max:20480']);
            $id = (int) $r->route('registration'); $this->data()->registration($actor, $tenant, $id);
            return app(RegistrationDocumentService::class)->stage($actor, $tenant, $id, (int) $d['Revision'], $d['Kind'], $r->file('file'));
        });
        return $this->transfer($r, $upload);
    }
    public function remove(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $d = $r->validate(['Revision' => 'required|integer|min:1']); $id = (int) $r->route('registration');
            $this->data()->registration($actor, $tenant, $id);
            app(RegistrationDocumentService::class)->remove($actor, $tenant, $id, (int) $r->route('file'), (int) $d['Revision']);
            return $this->data()->details($actor, $tenant, $id);
        });
    }
    public function retry(Request $r) {
        abort_unless(config('clubunio-mobile.uploads_enabled'), 503);
        $upload = $this->guarded($r, function ($actor, $tenant) use ($r) {
            $d = $r->validate(['Revision' => 'required|integer|min:1']); $id = (int) $r->route('registration');
            $registration = $this->data()->registration($actor, $tenant, $id);
            abort_unless($registration->revision === (int) $d['Revision'] && $registration->status?->workflow_key !== 'accepted', 409);
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'registrations', 'upload');
            $upload = app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'registration')->where('object_id', $id)->where('id', (int) $r->route('upload'))->first();
            abort_unless($upload, 404); return (int) $upload->id;
        });
        return $this->transfer($r, $upload);
    }
    private function transfer(Request $r, int $upload) {
        $actor = app(MobileSessions::class)->authenticate($r); $tenant = $r->attributes->get('mobile.tenant');
        app(MobileAccess::class)->enter($actor, $tenant); $id = (int) $r->route('registration');
        $this->data()->registration($actor, $tenant, $id);
        try { app(DocumentUploadService::class)->attempt($actor, $tenant, $upload); }
        catch (\Illuminate\Validation\ValidationException) {
            // The protected staging transaction remains committed for explicit retry.
            abort_unless(app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('id', $upload)->whereIn('state', ['pending', 'published'])->exists(), 409);
        }
        $actor = app(MobileSessions::class)->authenticate($r); app(MobileAccess::class)->enter($actor, $tenant);
        $stored = app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'registration')->where('object_id', $id)->find($upload);
        abort_unless($stored && in_array($stored->state, ['pending', 'published'], true), 409);
        $metadata = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($stored->encrypted_metadata), true, 512, JSON_THROW_ON_ERROR);
        return response()->json(['data' => ['success' => true, 'Registration' => $this->data()->details($actor, $tenant, $id),
            'Upload' => ['id' => (int) $stored->id, 'kind' => $metadata['kind'], 'sha256' => $stored->sha256, 'state' => $stored->state,
                'document_id' => $stored->result_id ? (int) $stored->result_id : null]]]);
    }
    public function file(Request $r) {
        $actor = $r->attributes->get('mobile.actor'); $tenant = $r->attributes->get('mobile.tenant'); $id = (int) $r->route('registration');
        $this->data()->registration($actor, $tenant, $id);
        $file = DB::connection('clubunio')->table('clubunio.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $id)->where('active', true)->find((int) $r->route('file'));
        abort_unless($file, 404);
        $response = app(MemberDocumentService::class)->openRegistration($actor, $tenant, (int) $file->id);
        $bytes = $response->getContent(); abort_unless(is_string($bytes) && strlen($bytes) > 0 && strlen($bytes) <= 20971520, 422);
        $response->headers->set('X-Content-SHA256', hash('sha256', $bytes)); $response->headers->set('Content-Length', (string) strlen($bytes));
        $response->headers->set('Cache-Control', 'no-store, private'); return $response;
    }
    private function write(Request $r, callable $action) {
        return response()->json(['data' => ['success' => true, 'Registration' => $this->guarded($r, $action)]]);
    }
    private function guarded(Request $r, callable $action) {
        abort_if(! $r->hasFile('file') && strlen($r->getContent()) > 200000, 422);
        return DB::connection('clubunio')->transaction(function () use ($r, $action) {
            $tenant = $r->attributes->get('mobile.tenant'); Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r); app(MobileAccess::class)->enter($actor, $tenant);
            return $action($actor, $tenant);
        });
    }
}
