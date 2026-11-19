<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Member, MemberDocument, Tenant};
use App\ClubUnio\Services\{LegacyMaterialReceiptService, MemberAccessService, MemberDocumentService, ModulePermissionService};
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Validator};

final class MobileMemberDocuments
{
    private const META = ['id', 'tenant_id', 'member_id', 'document_type_id', 'original_document_id', 'archive_receipt_id', 'archive_source_hash', 'created_by', 'request_key', 'title',
        'signature_required', 'state', 'revision', 'upload_fingerprint', 'original_sha256', 'original_byte_count', 'original_page_count',
        'signed_by', 'signed_at', 'signed_kind', 'signer_name_encrypted', 'signature_fingerprint', 'signed_sha256', 'signed_byte_count', 'signed_page_count', 'created_at', 'updated_at'];
    private const STATES = ['pending' => 'OFFEN', 'signed' => 'UNTERSCHRIEBEN', 'filed' => 'ABGELEGT'];

    public function table(Tenant $tenant) { return DB::connection('clubunio')->table('clubunio.mobile_member_receipts')->where('tenant_id', $tenant->id); }
    private function permissions() { return app(ModulePermissionService::class); }
    public function administration(Account $actor, Tenant $tenant): bool { return $this->permissions()->allows($actor, $tenant, 'documents', 'view'); }
    public function canUpload(Account $actor, Tenant $tenant): bool
    {
        return $this->permissions()->allows($actor, $tenant, 'documents', 'create') && $this->permissions()->allows($actor, $tenant, 'documents', 'upload');
    }
    private function represented(Account $actor, Tenant $tenant) { return app(MemberAccessService::class)->represented($actor, $tenant); }
    private function receipts(Account $actor, Tenant $tenant, bool $mine = false)
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        $query = $this->table($tenant);
        if ($mine || ! $this->administration($actor, $tenant)) { $query->whereIn('member_id', $this->represented($actor, $tenant)->select('clubunio.members.id')); }
        return $query;
    }
    private function files(Account $actor, Tenant $tenant, bool $mine = false)
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        $query = MemberDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('member', fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('archived_at'));
        if ($mine || ! $this->administration($actor, $tenant)) { $query->where('portal_visible', true)->whereIn('member_id', $this->represented($actor, $tenant)->select('clubunio.members.id')); }
        return $query;
    }
    private function archives(Account $actor, Tenant $tenant, bool $mine = false)
    {
        return app(LegacyMaterialReceiptService::class)->visible($actor, $tenant, ! $mine && $this->administration($actor, $tenant));
    }
    private function receipt(Account $actor, Tenant $tenant, int $id, bool $bytes = false, bool $lock = false): object
    {
        return $this->receipts($actor, $tenant)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail($bytes ? ['*'] : self::META);
    }

    public function ownDocumentCount(Account $actor, Tenant $tenant): int
    {
        return $this->documentList($actor, $tenant, true)->count();
    }

    private function documentList(Account $actor, Tenant $tenant, bool $mine)
    {
        $receipts = $this->receipts($actor, $tenant, $mine)->whereNull('archive_receipt_id')->selectRaw("id, member_id, created_at, 'receipt' AS source_kind, CASE state WHEN 'pending' THEN 'OFFEN' WHEN 'signed' THEN 'UNTERSCHRIEBEN' ELSE 'ABGELEGT' END AS status");
        $archives = $this->archives($actor, $tenant, $mine)->leftJoinSub($this->table($tenant)->whereNotNull('archive_receipt_id')->select(['archive_receipt_id', 'state']), 'forward',
            fn ($j) => $j->on('forward.archive_receipt_id', '=', 'clubunio.legacy_material_receipts.id'))
            ->selectRaw("id, member_id, created_at, 'archive' AS source_kind, CASE forward.state WHEN 'signed' THEN 'UNTERSCHRIEBEN' WHEN 'pending' THEN 'OFFEN' ELSE source_state END AS status")->toBase();
        $archiveRows = DB::connection('clubunio')->table('clubunio.legacy_material_receipts')->where('tenant_id', $tenant->id);
        $files = $this->files($actor, $tenant, $mine)->whereNotIn('id', (clone $archiveRows)->select('original_document_id'))
            ->whereNotIn('id', (clone $archiveRows)->whereNotNull('signed_document_id')->select('signed_document_id'))
            ->selectRaw("id, member_id, created_at, 'file' AS source_kind, 'ABGELEGT' AS status")->toBase();
        $union = $receipts->unionAll($archives)->unionAll($files);
        return DB::connection('clubunio')->query()->fromSub($union, 'documents');
    }

    public function index(Request $r, Account $actor, Tenant $tenant): array
    {
        $d = $r->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:50',
            'mine' => 'nullable|boolean', 'MitgliederID' => 'nullable|integer|min:1', 'Status' => 'nullable|in:OFFEN,UNTERSCHRIEBEN,ABGELEGT']);
        $query = $this->documentList($actor, $tenant, (bool) ($d['mine'] ?? true));
        if (isset($d['MitgliederID'])) { $query->where('member_id', $d['MitgliederID']); }
        if (! empty($d['Status'])) { $query->where('status', $d['Status']); }
        $total = (clone $query)->count();
        $page = $query->orderByDesc('created_at')->orderBy('source_kind')->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 20)->get();
        return ['success' => true, 'Dokumente' => $this->listViews($actor, $tenant, $page),
            'totalRecordCount' => $total, 'CanUpload' => $this->canUpload($actor, $tenant), 'CanReadAll' => $this->administration($actor, $tenant)];
    }

    /** Bounded metadata only. Every source retains its own fresh visible query. */
    private function listViews(Account $actor, Tenant $tenant, $page): array
    {
        if ($page->isEmpty()) return [];
        $archiveIds = $page->where('source_kind', 'archive')->pluck('id');
        $receiptIds = $page->where('source_kind', 'receipt')->pluck('id');
        $fileIds = $page->where('source_kind', 'file')->pluck('id');
        $receipts = $this->receipts($actor, $tenant)->where(fn ($q) => $q->whereIn('id', $receiptIds)->orWhereIn('archive_receipt_id', $archiveIds))->get(self::META);
        $read = ['receipts' => $receipts->keyBy('id'), 'forward' => $receipts->whereNotNull('archive_receipt_id')->keyBy('archive_receipt_id'),
            'files' => $fileIds->isEmpty() ? collect() : $this->files($actor, $tenant)->with('documentType')->whereKey($fileIds)->get()->keyBy('id'),
            'archives' => $archiveIds->isEmpty() ? collect() : $this->archives($actor, $tenant)->whereKey($archiveIds)->get()->keyBy('id'),
            'names' => Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $page->pluck('member_id'))->get(['id','first_name','last_name'])->keyBy('id')->all(),
            'own' => array_fill_keys($this->represented($actor, $tenant)->whereIn('id', $page->pluck('member_id'))->pluck('clubunio.members.id')->all(), true),
            'upload' => $this->canUpload($actor, $tenant)];
        return $page->map(fn ($row) => $this->viewData($actor, $tenant, (int) $row->id, $row->source_kind, $read))->all();
    }

    public function types(Account $actor, Tenant $tenant): array
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless($this->canUpload($actor, $tenant), 403);
        return ['success' => true, 'DokumentTypen' => DB::connection('clubunio')->table('clubunio.document_types')->where('tenant_id', $tenant->id)->where('active', true)
            ->orderBy('name')->orderBy('id')->get(['id', 'code', 'name'])->map(fn ($t) => ['DokumentTypID' => (int) $t->id, 'Code' => $t->code, 'Bezeichnung' => $t->name])->all()];
    }

    public function members(Request $r, Account $actor, Tenant $tenant): array
    {
        app(MobileAccess::class)->enter($actor, $tenant); abort_unless($this->canUpload($actor, $tenant), 403);
        $d = $r->validate(['q' => 'required|string|min:2|max:120', 'MitgliederID' => 'nullable|integer|min:1']);
        $search = trim($d['q']); abort_if(mb_strlen($search) < 2, 422);
        $query = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at');
        if (isset($d['MitgliederID'])) { $query->whereKey($d['MitgliederID']); }
        return ['success' => true, 'Mitglieder' => $query->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%'))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(20)->get(['id', 'first_name', 'last_name', 'city'])
            ->map(fn ($m) => ['MitgliederID' => (int) $m->id, 'Vorname' => $m->first_name, 'Nachname' => $m->last_name, 'Ort' => $m->city])->all()];
    }

    public function view(Account $actor, Tenant $tenant, int $id, string $source = 'receipt'): array
    {
        return $this->viewData($actor, $tenant, $id, $source);
    }

    private function viewData(Account $actor, Tenant $tenant, int $id, string $source, ?array $read = null): array
    {
        if ($source === 'file') {
            $row = $read === null ? $this->files($actor, $tenant)->with('documentType')->findOrFail($id) : ($read['files'][$id] ?? abort(404));
            $title = $row->original_filename ?: $row->documentType?->name ?: 'Mitgliederdokument';
            return $this->names($tenant, (int) $row->member_id, $read['names'] ?? null) + ['QuittungID' => (int) $row->id, 'SourceKind' => 'file', 'Revision' => (int) $row->revision,
                'Titel' => $title, 'Status' => 'ABGELEGT', 'StatusText' => 'Mitgliederdokument', 'CanSign' => false, 'UnterschriftNotwendig' => false,
                'OriginalSha256' => $row->sha256, 'CanUpload' => $read['upload'] ?? $this->canUpload($actor, $tenant)];
        }
        if ($source === 'archive') {
            $row = $read === null ? $this->archives($actor, $tenant)->findOrFail($id) : ($read['archives'][$id] ?? abort(404));
            $forward = $read === null ? $this->receipts($actor, $tenant)->where('archive_receipt_id', $id)->first(self::META) : ($read['forward'][$id] ?? null);
            if ($forward) {
                $this->validateArchive($forward, $row);
                return array_replace($this->viewData($actor, $tenant, (int) $forward->id, 'receipt', $read), ['QuittungID' => $id, 'SourceKind' => 'archive', 'SourceRevision' => $row->source_hash]);
            }
            $canSign = $row->source_state === 'OFFEN' && ! $row->signed_document_id && ($read === null ? $this->represented($actor, $tenant)->whereKey($row->member_id)->exists() : isset($read['own'][$row->member_id]));
            return $this->names($tenant, (int) $row->member_id, $read['names'] ?? null) + ['QuittungID' => (int) $row->id, 'SourceKind' => 'archive',
                'Titel' => $row->title, 'Status' => $row->source_state, 'StatusText' => $canSign ? 'Zur Unterschrift' : 'Historische Quittung (schreibgeschuetzt)',
                'CanSign' => $canSign, 'Revision' => 1, 'SourceRevision' => $row->source_hash, 'UnterschriftNotwendig' => $row->source_state !== 'ABGELEGT', 'OriginalSha256' => $row->original_sha256,
                'SigniertSha256' => $row->signed_sha256, 'SigniertAm' => $row->signed_at?->toIso8601String()];
        }
        abort_unless($source === 'receipt', 404);
        $row = $read === null ? $this->receipt($actor, $tenant, $id) : ($read['receipts'][$id] ?? abort(404));
        return $this->names($tenant, (int) $row->member_id, $read['names'] ?? null) + ['QuittungID' => (int) $row->id, 'SourceKind' => 'receipt', 'Revision' => (int) $row->revision,
            'Titel' => $row->title, 'Status' => self::STATES[$row->state], 'UnterschriftNotwendig' => (bool) $row->signature_required,
            'CanSign' => $row->state === 'pending' && ($read === null ? $this->represented($actor, $tenant)->whereKey($row->member_id)->exists() : isset($read['own'][$row->member_id])),
            'OriginalSha256' => $row->original_sha256, 'SigniertSha256' => $row->signed_sha256, 'SigniertAm' => $row->signed_at,
            'OriginalBytes' => (int) $row->original_byte_count, 'SignedBytes' => $row->signed_byte_count === null ? null : (int) $row->signed_byte_count];
    }
    private function names(Tenant $tenant, int $member, ?array $names = null): array
    {
        $row = $names === null ? Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($member)->firstOrFail(['id', 'first_name', 'last_name']) : ($names[$member] ?? abort(404));
        return ['MitgliederID' => (int) $row->id, 'Vorname' => $row->first_name, 'Nachname' => $row->last_name];
    }

    public function upload(Account $actor, Tenant $tenant, array $input, UploadedFile $file): array
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0 && $actor->fresh()?->active && ! $actor->fresh()?->api_only, 403);
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless($this->canUpload($actor, $tenant), 403);
        abort_unless(config('clubunio-mobile.uploads_enabled'), 503);
        $d = Validator::make($input + ['file' => $file], ['MitgliederID' => 'required|integer|min:1', 'Titel' => 'required|string|max:160|not_regex:/[\x00-\x1f\x7f]/',
            'DokumentTypID' => 'required|integer|min:1', 'UnterschriftNotwendig' => 'required|boolean', 'RequestKey' => 'required|regex:/^[a-zA-Z0-9-]{16,64}$/',
            'file' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png'])->validate();
        $title = trim($d['Titel']); abort_unless($title !== '', 422);
        abort_if(str_starts_with($d['RequestKey'], 'archive-'), 422, 'Reservierter Anfrageschluessel.');
        Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')->findOrFail($d['MitgliederID']);
        DB::connection('clubunio')->table('clubunio.document_types')->where('tenant_id', $tenant->id)->where('active', true)->where('id', $d['DokumentTypID'])->firstOrFail();
        $raw = $file->get(); abort_unless(strlen($raw) > 0 && strlen($raw) <= MobileMemberDocumentPdf::MAX_ORIGINAL, 422);
        $verdict = app(MalwareScanner::class)->scan($raw);
        abort_unless(isset($verdict['clean'], $verdict['version']) && is_bool($verdict['clean']) && is_string($verdict['version']) && $verdict['version'] !== '', 503);
        abort_unless($verdict['clean'], 422, 'Dokumentdatei wurde abgelehnt.');
        try { $original = app(MobileMemberDocumentPdf::class)->normalize($raw); }
        catch (\InvalidArgumentException $error) { abort(422, $error->getMessage()); }
        $hash = hash('sha256', $original['bytes']);
        $fingerprint = hash('sha256', json_encode([(int) $d['MitgliederID'], (int) $d['DokumentTypID'], $title, (bool) $d['UnterschriftNotwendig'], $hash], JSON_THROW_ON_ERROR));
        $old = $this->table($tenant)->where('created_by', $actor->id)->where('request_key', $d['RequestKey'])->first(self::META);
        if ($old) { abort_unless(hash_equals($old->upload_fingerprint, $fingerprint), 409, 'Dieser Anfrageschluessel ist bereits anders verwendet.'); return $this->view($actor, $tenant, (int) $old->id); }
        $bytesHeld = (int) $this->table($tenant)->selectRaw('COALESCE(SUM(original_byte_count + COALESCE(signed_byte_count, 0)),0) AS held')->value('held');
        abort_if($this->table($tenant)->count() >= 1000 || $bytesHeld + strlen($original['bytes']) > 536_870_912, 429, 'Dokumentenspeicher ist belegt.');
        $id = $this->table($tenant)->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $d['MitgliederID'], 'document_type_id' => $d['DokumentTypID'],
            'created_by' => $actor->id, 'request_key' => $d['RequestKey'], 'title' => $title, 'signature_required' => (bool) $d['UnterschriftNotwendig'],
            'state' => $d['UnterschriftNotwendig'] ? 'pending' : 'filed', 'upload_fingerprint' => $fingerprint,
            'original_sha256' => $hash, 'original_pdf_encrypted' => Crypt::encryptString($original['bytes']), 'original_byte_count' => strlen($original['bytes']), 'original_page_count' => $original['pages'],
            'scanner_version' => mb_substr($verdict['version'], 0, 160), 'scanned_at' => now()->toIso8601String()]);
        $this->audit($actor, $tenant, (int) $id, 'issued', 1);
        return $this->view($actor, $tenant, (int) $id);
    }

    public function sign(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 500);
        $row = $this->receipt($actor, $tenant, $id, true, true);
        if ($row->archive_receipt_id) { $this->validateArchive($row, $this->archives($actor, $tenant)->findOrFail($row->archive_receipt_id)); }
        $member = $this->represented($actor, $tenant)->whereKey($row->member_id)->firstOrFail();
        $d = Validator::make($input, ['Revision' => 'required|integer|min:1', 'OriginalSha256' => 'required|regex:/^[0-9a-f]{64}$/',
            'Accepted' => 'required|accepted', 'Strokes' => 'required|array|min:1|max:100'])->validate();
        abort_unless(($input['Accepted'] ?? null) === true, 422);
        try { $strokes = app(MobileMemberDocumentPdf::class)->strokes($d['Strokes']); }
        catch (\InvalidArgumentException $error) { abort(422, $error->getMessage()); }
        abort_unless(hash_equals($row->original_sha256, $d['OriginalSha256']), 409, 'Bitte das aktuelle Original erneut oeffnen.');
        $original = $this->originalBytes($row);
        $fingerprint = hash('sha256', json_encode([(int) $actor->id, (int) $row->member_id, $d['OriginalSha256'], $strokes], JSON_THROW_ON_ERROR));
        if ($row->state === 'signed') {
            abort_unless((int) $row->signed_by === (int) $actor->id && hash_equals($row->signature_fingerprint, $fingerprint)
                && (int) $d['Revision'] === (int) $row->revision - 1, 409, 'Quittung ist bereits anders unterschrieben.');
            $saved = Crypt::decryptString($row->signed_pdf_encrypted);
            abort_unless(strlen($saved) === (int) $row->signed_byte_count && hash_equals($row->signed_sha256, hash('sha256', $saved)), 409);
            return $this->view($actor, $tenant, $id);
        }
        abort_unless($row->state === 'pending' && $row->signature_required && (int) $row->revision === (int) $d['Revision'], 409, 'Quittung wurde inzwischen geaendert.');
        if ($row->original_document_id && ! $row->archive_receipt_id) {
            $source = $this->files($actor, $tenant)->where('member_id', $row->member_id)->findOrFail($row->original_document_id);
            abort_unless($source->sha256 && hash_equals($source->sha256, $row->original_sha256), 409);
        }
        $own = $this->represented($actor, $tenant)->whereKey($member->id)->whereIn('id', app(MemberAccessService::class)->represented($actor, $tenant, true)->select('clubunio.members.id'))->exists();
        $kind = $own ? 'self' : 'parent'; $signedAt = now('UTC')->toImmutable();
        $signer = $actor->fresh(); abort_unless($signer?->active && ! $signer->api_only, 403);
        $signerName = trim($signer->display_name ?? '') ?: $signer->username;
        try { $signed = app(MobileMemberDocumentPdf::class)->sign($original, $strokes, ['club' => $tenant->name, 'title' => $row->title,
            'member' => trim($member->first_name.' '.$member->last_name), 'signer' => $signerName, 'username' => $signer->username,
            'signed_at' => $signedAt->format('Y-m-d H:i:s'), 'receipt' => (string) $id, 'kind' => $kind, 'original_sha256' => $row->original_sha256]); }
        catch (\InvalidArgumentException $error) { abort(422, $error->getMessage()); }
        abort_unless($signed['pages'] === (int) $row->original_page_count + 1 && hash_equals($row->original_sha256, hash('sha256', $original)), 409);
        $bytesHeld = (int) $this->table($tenant)->selectRaw('COALESCE(SUM(original_byte_count + COALESCE(signed_byte_count, 0)),0) AS held')->value('held');
        abort_if($bytesHeld + strlen($signed['bytes']) > 536_870_912, 429);
        $updated = $this->table($tenant)->where('id', $id)->where('revision', $d['Revision'])->update(['state' => 'signed', 'revision' => (int) $row->revision + 1,
            'signed_by' => $actor->id, 'signed_at' => $signedAt->toIso8601String(), 'signed_kind' => $kind, 'signer_name_encrypted' => Crypt::encryptString($signerName),
            'strokes_encrypted' => Crypt::encryptString(json_encode($strokes, JSON_THROW_ON_ERROR)), 'signature_fingerprint' => $fingerprint,
            'signed_sha256' => hash('sha256', $signed['bytes']), 'signed_pdf_encrypted' => Crypt::encryptString($signed['bytes']),
            'signed_byte_count' => strlen($signed['bytes']), 'signed_page_count' => $signed['pages'], 'updated_at' => $signedAt->toIso8601String()]);
        abort_unless($updated === 1, 409);
        $this->audit($actor, $tenant, $id, 'signed', (int) $row->revision + 1);
        return $this->view($actor, $tenant, $id);
    }

    private function validateArchive(object $forward, object $archive): void
    {
        abort_unless($archive->source_state === 'OFFEN' && ! $archive->signed_document_id && (int) $forward->member_id === (int) $archive->member_id
            && (int) $forward->original_document_id === (int) $archive->original_document_id
            && hash_equals($forward->archive_source_hash, $archive->source_hash) && hash_equals($forward->original_sha256, $archive->original_sha256), 409, 'Archivauftrag wurde inzwischen geaendert.');
    }
    public function signArchive(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 500);
        $archive = $this->archives($actor, $tenant)->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->represented($actor, $tenant)->whereKey($archive->member_id)->firstOrFail();
        $d = Validator::make($input, ['SourceRevision' => 'required|regex:/^[0-9a-f]{64}$/', 'Revision' => 'required|integer|min:1',
            'OriginalSha256' => 'required|regex:/^[0-9a-f]{64}$/', 'Accepted' => 'required|accepted', 'Strokes' => 'required|array|min:1|max:100'])->validate();
        abort_unless(($input['Accepted'] ?? null) === true && hash_equals($archive->source_hash, $d['SourceRevision'])
            && hash_equals($archive->original_sha256, $d['OriginalSha256']), 409, 'Bitte den aktuellen Archivauftrag neu laden.');
        abort_unless($archive->source_state === 'OFFEN' && ! $archive->signed_document_id, 409, 'Historischer Nachweis bleibt unveraendert.');
        $forward = $this->table($tenant)->where('archive_receipt_id', $id)->first(self::META);
        if (! $forward) {
            abort_unless((int) $d['Revision'] === 1, 409);
            $source = MemberDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $archive->member_id)->where('active', true)->findOrFail($archive->original_document_id);
            $response = app(LegacyMaterialReceiptService::class)->pdf($actor, $tenant, $id, false, true);
            $bytes = $response->getContent(); abort_unless(is_string($bytes) && hash_equals($archive->original_sha256, hash('sha256', $bytes)), 409);
            $verdict = app(MalwareScanner::class)->scan($bytes);
            abort_unless(isset($verdict['clean'], $verdict['version']) && is_bool($verdict['clean']) && is_string($verdict['version']) && $verdict['version'] !== '', 503);
            abort_unless($verdict['clean'], 422, 'Originaldokument wurde abgelehnt.');
            try { $original = app(MobileMemberDocumentPdf::class)->normalize($bytes); app(MobileMemberDocumentPdf::class)->strokes($d['Strokes']); }
            catch (\InvalidArgumentException $error) { abort(422, $error->getMessage()); }
            abort_unless(hash_equals($archive->original_sha256, hash('sha256', $original['bytes'])), 409);
            $held = (int) $this->table($tenant)->selectRaw('COALESCE(SUM(original_byte_count + COALESCE(signed_byte_count,0)),0) AS held')->value('held');
            abort_if($this->table($tenant)->count() >= 1000 || $held + strlen($bytes) > 536_870_912, 429);
            $receipt = $this->table($tenant)->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $archive->member_id, 'document_type_id' => $source->document_type_id,
                'original_document_id' => $source->id, 'archive_receipt_id' => $archive->id, 'archive_source_hash' => $archive->source_hash,
                'created_by' => $actor->id, 'request_key' => 'archive-'.substr(hash('sha256', $tenant->id.':'.$id), 0, 48),
                'title' => $archive->title, 'signature_required' => true, 'state' => 'pending', 'upload_fingerprint' => hash('sha256', $archive->source_hash.':'.$archive->original_sha256),
                'original_sha256' => $archive->original_sha256, 'original_pdf_encrypted' => Crypt::encryptString($bytes), 'original_byte_count' => strlen($bytes), 'original_page_count' => $original['pages'],
                'scanner_version' => mb_substr($verdict['version'], 0, 160), 'scanned_at' => now()->toIso8601String()]);
            $this->audit($actor, $tenant, (int) $receipt, 'archive_forwarded', 1);
        } else { $this->validateArchive($forward, $archive); $receipt = (int) $forward->id; }
        $this->sign($actor, $tenant, (int) $receipt, $input);
        return $this->view($actor, $tenant, $id, 'archive');
    }

    private function originalBytes(object $row): string
    {
        $bytes = Crypt::decryptString($row->original_pdf_encrypted);
        abort_unless(strlen($bytes) === (int) $row->original_byte_count && hash_equals($row->original_sha256, hash('sha256', $bytes)), 409, 'Original wurde inzwischen geaendert.');
        return $bytes;
    }
    public function pdf(Account $actor, Tenant $tenant, int $id, bool $original = false, string $source = 'receipt')
    {
        if ($source === 'file') {
            $row = $this->files($actor, $tenant)->findOrFail($id);
            $response = $this->administration($actor, $tenant) ? app(MemberDocumentService::class)->open($actor, $tenant, $id) : app(MemberDocumentService::class)->openPortal($actor, $tenant, $id);
            $bytes = $response->getContent();
            abort_unless(is_string($bytes) && (! $row->sha256 || hash_equals($row->sha256, hash('sha256', $bytes))), 409);
            try { $bytes = app(MobileMemberDocumentPdf::class)->normalize($bytes)['bytes']; }
            catch (\InvalidArgumentException $error) { abort(422, $error->getMessage()); }
            return $this->response($bytes, $id);
        }
        if ($source === 'archive') {
            $archive = $this->archives($actor, $tenant)->findOrFail($id);
            $forward = $this->receipts($actor, $tenant)->where('archive_receipt_id', $id)->first(self::META);
            if ($forward) { $this->validateArchive($forward, $archive); return $this->pdf($actor, $tenant, (int) $forward->id, $original); }
            $response = app(LegacyMaterialReceiptService::class)->pdf($actor, $tenant, $id, $this->administration($actor, $tenant), $original);
            return $this->response($response->getContent(), $id);
        }
        abort_unless($source === 'receipt', 404);
        $row = $this->receipt($actor, $tenant, $id, true);
        if ($this->permissions()->isProfile($actor, $tenant)) { $this->permissions()->authorize($actor, $tenant, 'documents', 'download'); }
        $bytes = $this->originalBytes($row);
        if (! $original && $row->state === 'signed') {
            $bytes = Crypt::decryptString($row->signed_pdf_encrypted);
            abort_unless(strlen($bytes) === (int) $row->signed_byte_count && hash_equals($row->signed_sha256, hash('sha256', $bytes)), 409);
        }
        return $this->response($bytes, $id);
    }
    private function response(string $bytes, int $id)
    {
        abort_unless(strlen($bytes) <= MobileMemberDocumentPdf::MAX_SIGNED && str_starts_with($bytes, '%PDF-'), 409);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => strlen($bytes),
            'Content-Disposition' => 'inline; filename="Dokument-'.$id.'.pdf"', 'X-Content-SHA256' => hash('sha256', $bytes),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }
    private function audit(Account $actor, Tenant $tenant, int $id, string $operation, int $revision): void
    {
        DB::connection('clubunio')->table('clubunio.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'mobile_member_receipts', 'object_id' => $id, 'after_value' => json_encode(['operation' => $operation, 'revision' => $revision], JSON_THROW_ON_ERROR)]);
    }
}
