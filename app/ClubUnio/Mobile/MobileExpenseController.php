<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{ExpenseLine, ExpenseStatus, Tenant};
use App\ClubUnio\Services\{BankAccountService, ExpenseService, MemberDocumentService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileExpenseController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileExpenseReady::class]; }
    private function actor(Request $r) { return $r->attributes->get('mobile.actor'); }
    private function tenant(Request $r) { return $r->attributes->get('mobile.tenant'); }
    private function pending(Tenant $tenant, int $expense): bool {
        return app(\App\ClubUnio\Services\DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'expense')->where('state', 'pending')
            ->whereIn('object_id', ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('expense_id', $expense)->select('clubora.expense_lines.id'))->exists();
    }
    private function write(Request $r, callable $operation) {
        return DB::connection('clubunio')->transaction(function () use ($r, $operation) {
            $tenant = $this->tenant($r);
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            return response()->json(['data' => $operation($actor, $tenant)]);
        });
    }
    public function index(Request $r) {
        $d = $r->validate(['status' => 'nullable|in:O,F,Z,B,R', 'start' => 'nullable|integer|min:0|max:100000', 'limit' => 'nullable|integer|min:1|max:100']);
        $q = app(ExpenseService::class)->visible($this->actor($r), $this->tenant($r))
            ->when(isset($d['status']), fn ($q) => $q->whereHas('status', fn ($s) => $s->where('code', $d['status'])));
        $total = (clone $q)->count();
        $rows = $q->with(['member','teamSeason.team','season','round','status'])->orderByDesc('id')->offset($d['start'] ?? 0)->limit($d['limit'] ?? 20)->get();
        $capabilities = app(ExpenseService::class)->readCapabilities($this->actor($r), $this->tenant($r), $rows);
        $closed = ['CanManagePositions' => false, 'CanAddPosition' => false, 'CanDeletePosition' => false, 'AllowedStatusCodes' => []];
        return response()->json(['data' => ['rows' => $rows->map(fn ($e) => app(MobileExpenseData::class)->receipt($this->actor($r), $this->tenant($r), $e,
            $capabilities[(int) $e->id] ?? $closed))->all(), 'total' => $total]]);
    }
    public function show(Request $r) { return response()->json(['data' => app(MobileExpenseData::class)->detail($this->actor($r), $this->tenant($r), (int) $r->route('expense'))]); }
    public function options(Request $r) {
        abort_unless(app(ExpenseService::class)->portalAllowed($this->actor($r), $this->tenant($r)) || app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($this->actor($r), $this->tenant($r), 'expenses', 'view'), 403);
        return response()->json(['data' => app(MobileExpenseData::class)->options($this->actor($r), $this->tenant($r))]);
    }
    public function save(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $id = $r->route('expense') ? (int) $r->route('expense') : null;
            if ($id) app(ExpenseService::class)->visible($actor, $tenant)->findOrFail($id);
            abort_if($id && $this->pending($tenant, $id), 409, 'Bitte zuerst die offene Beleguebertragung abschliessen.');
            $d = $r->validate(['command_key' => $id ? 'prohibited' : 'required|uuid', 'revision' => $id ? 'required|integer|min:1' : 'prohibited', 'member_id' => 'required|integer|min:1',
                'team_season_id' => 'required|integer|min:1', 'bank_account_id' => 'required|integer|min:1', 'round_id' => 'required|integer|min:1', 'notes' => 'nullable|string|max:4000']);
            $key = $d['command_key'] ?? null; unset($d['command_key']);
            $commands = app(MobileExpenseCommands::class); $fingerprint = $commands->fingerprint($d);
            if ($key && ($replay = $commands->replay($actor, $tenant, $key, 'create_receipt', $fingerprint))) return $replay;
            $expense = app(ExpenseService::class)->save($actor, $tenant, $id, $d);
            if ($key) $commands->remember($actor, $tenant, $key, 'create_receipt', $fingerprint, (int) $expense->id);
            return app(MobileExpenseData::class)->detail($actor, $tenant, (int) $expense->id);
        });
    }
    public function transition(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            // No mobile endpoint can record a real payment as paid.
            $d = $r->validate(['revision' => 'required|integer|min:1', 'status' => 'required|in:O,F,Z,R', 'reason' => 'required|string|min:10|max:500']);
            app(ExpenseService::class)->visible($actor, $tenant)->findOrFail((int) $r->route('expense'));
            abort_if(! in_array($d['status'], ['F','Z'], true) && $this->pending($tenant, (int) $r->route('expense')), 409, 'Bitte zuerst die offene Beleguebertragung abschliessen.');
            $status = ExpenseStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('code', $d['status'])->firstOrFail();
            $expense = app(ExpenseService::class)->transition($actor, $tenant, (int) $r->route('expense'), ['revision' => $d['revision'], 'status_id' => $status->id, 'reason' => $d['reason']]);
            return app(MobileExpenseData::class)->detail($actor, $tenant, (int) $expense->id);
        });
    }
    public function banks(Request $r) {
        $actor = $this->actor($r); $tenant = $this->tenant($r);
        // Original bank management is personal, even for staff with wider member access.
        $own = app(\App\ClubUnio\Services\MemberAccessService::class)->represented($actor, $tenant, true)->select('clubora.members.id');
        $banks = app(BankAccountService::class)->visible($actor, $tenant)->whereIn('member_id', $own)->where('active', true)->orderBy('id')->get();
        return response()->json(['data' => ['rows' => $banks->map(fn ($bank) => app(MobileExpenseData::class)->bank($actor, $tenant, $bank))->all()]]);
    }
    public function bank(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $id = $r->route('bank') ? (int) $r->route('bank') : null;
            $d = $r->validate(['revision' => $id ? 'required|integer|min:1' : 'prohibited', 'member_id' => 'required|integer|min:1',
                'bank_id' => $r->isMethod('DELETE') ? 'prohibited' : 'required|integer|min:1', 'iban' => $r->isMethod('DELETE') ? 'prohibited' : 'required|string|max:60']);
            abort_unless(app(\App\ClubUnio\Services\MemberAccessService::class)->represented($actor, $tenant, true)->whereKey($d['member_id'])->exists(), 403);
            $input = ['revision' => $d['revision'] ?? 0, 'active' => ! $r->isMethod('DELETE')];
            if ($r->isMethod('DELETE')) {
                $old = app(BankAccountService::class)->visible($actor, $tenant)->where('member_id', $d['member_id'])->findOrFail($id);
                $input['bank_id'] = $old->bank_id;
            } else { $input += ['bank_id' => $d['bank_id'], 'iban' => $d['iban']]; }
            $bank = app(BankAccountService::class)->save($actor, $tenant, (int) $d['member_id'], $id, $input);
            return $r->isMethod('DELETE') ? ['removed' => true, 'id' => (int) $bank->id] : ['bank' => app(MobileExpenseData::class)->bank($actor, $tenant, $bank)];
        });
    }
    public function line(Request $r) {
        $response = $this->write($r, function ($actor, $tenant) use ($r) {
            $expense = (int) $r->route('expense'); $id = $r->route('line') ? (int) $r->route('line') : null;
            app(ExpenseService::class)->visible($actor, $tenant)->findOrFail($expense);
            $delete = $r->isMethod('DELETE');
            $d = $r->validate(['command_key' => $id ? 'prohibited' : 'required|uuid', 'revision' => $id ? 'required|integer|min:1' : 'prohibited', 'expense_revision' => 'required|integer|min:1',
                'expense_type_id' => $delete ? 'prohibited' : 'required|integer|min:1', 'game_type_id' => 'nullable|integer|min:1',
                'opponent' => 'nullable|string|max:255', 'amount' => $delete ? 'prohibited' : 'required|regex:/^\d{1,9}(\.\d{1,4})?$/', 'notes' => 'nullable|string|max:4000',
                'receipt_token' => $delete ? 'prohibited' : 'nullable|uuid|required_with:ocr_work_id', 'receipt_revision' => 'required_with:receipt_token|integer|min:1',
                'ocr_work_id' => $delete ? 'prohibited' : 'nullable|uuid|required_with:ocr_source_revision,ai_suggested_amount',
                'ocr_source_revision' => $delete ? 'prohibited' : 'required_with:ocr_work_id|integer|min:1',
                'ai_suggested_amount' => $delete ? 'prohibited' : 'required_with:ocr_work_id|regex:/^\d{1,9}(\.\d{1,4})?$/']);
            $key = $d['command_key'] ?? null; unset($d['command_key']);
            $commands = app(MobileExpenseCommands::class);
            $commandFingerprint = $commands->fingerprint(['expense_id' => $expense] + $d);
            if ($key && ($replay = $commands->replay($actor, $tenant, $key, 'create_line', $commandFingerprint))) return $replay;
            $drafts = app(MobileExpenseReceipts::class);
            $fingerprint = $drafts->fingerprint(['expense_id' => $expense, 'line_id' => $id] + $d);
            if (isset($d['receipt_token']) && ($bound = $drafts->replay($actor, $tenant, $d['receipt_token'], $fingerprint))) {
                $boundUpload = (int) $drafts->owned($actor, $tenant, $d['receipt_token'])->bound_upload_id;
                if ($key) $commands->remember($actor, $tenant, $key, 'create_line', $commandFingerprint, $expense, $bound, $boundUpload);
                return app(MobileExpenseData::class)->detail($actor, $tenant, $expense) + ['line_id' => $bound, 'replayed' => true,
                    'upload_id' => $boundUpload];
            }
            abort_if($this->pending($tenant, $expense), 409, 'Bitte zuerst die offene Beleguebertragung abschliessen.');
            $provenance = app(MobileExpenseOcrProvenance::class);
            $claim = $delete ? null : $provenance->claim($actor, $tenant, $d);
            $input = array_diff_key($d, array_fill_keys(['receipt_token', 'receipt_revision', 'ocr_work_id', 'ocr_source_revision', 'ai_suggested_amount'], true));
            if ($delete) { $input['active'] = false; }
            $line = app(ExpenseService::class)->saveLine($actor, $tenant, $expense, $id, $input);
            $upload = isset($d['receipt_token']) ? $drafts->bind($actor, $tenant, $d['receipt_token'], $d['receipt_revision'], $line, $fingerprint) : null;
            $provenance->record($actor, $tenant, $line, $claim, isset($d['receipt_token']));
            if ($key) $commands->remember($actor, $tenant, $key, 'create_line', $commandFingerprint, $expense, (int) $line->id, $upload);
            return app(MobileExpenseData::class)->detail($actor, $tenant, $expense) + ['line_id' => (int) $line->id, 'upload_id' => $upload,
                'upload_state' => $upload ? 'pending' : null, 'removed' => $delete];
        });
        $data = $response->getData(true)['data'];
        return isset($data['upload_id']) ? $this->syncUpload($r, (int) $r->route('expense'), (int) $data['upload_id'], (int) $data['line_id'], $data['replayed'] ?? false) : $response;
    }
    private function syncUpload(Request $r, int $expense, int $uploadId, ?int $lineId = null, bool $replayed = false) {
        // Provider transfer starts after the atomic draft/line binding has committed.
        $actor = app(MobileSessions::class)->authenticate($r); $tenant = $this->tenant($r);
        app(MobileAccess::class)->enter($actor, $tenant);
        $service = app(\App\ClubUnio\Services\DocumentUploadService::class);
        $lineIds = ExpenseLine::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('expense_id', $expense)->select('clubora.expense_lines.id');
        app(ExpenseService::class)->visible($actor, $tenant)->findOrFail($expense);
        $upload = $service->table()->where('tenant_id', $tenant->id)->where('kind', 'expense')->whereIn('object_id', $lineIds)->find($uploadId);
        abort_unless($upload && in_array($upload->state, ['pending','published'], true), 404);
        abort_unless((int) $upload->created_by === (int) $actor->id || \App\ClubUnio\Services\BexioConnectionService::canManage($actor, $tenant), 403);
        try { $service->attempt($actor, $tenant, $uploadId); }
        catch (\Illuminate\Validation\ValidationException) { /* Honest pending state remains retryable. */ }
        $state = $service->table()->where('tenant_id', $tenant->id)->where('id', $uploadId)->value('state');
        return response()->json(['data' => app(MobileExpenseData::class)->detail($actor, $tenant, $expense)
            + ['line_id' => $lineId ?? (int) $upload->object_id, 'upload_id' => $uploadId, 'upload_state' => $state, 'replayed' => $replayed]]);
    }
    public function retryUpload(Request $r) {
        return $this->syncUpload($r, (int) $r->route('expense'), (int) $r->route('upload'));
    }
    public function uploadReceipt(Request $r) {
        return $this->write($r, function ($actor, $tenant) use ($r) {
            $d = $r->validate(['client_id' => 'required|uuid', 'file' => 'required|file|max:5120']);
            return app(MobileExpenseReceipts::class)->stage($actor, $tenant, $d['client_id'], $r->file('file'));
        });
    }
    public function discardReceipt(Request $r) { return $this->write($r, function ($actor, $tenant) use ($r) { app(MobileExpenseReceipts::class)->discard($actor, $tenant, $r->route('receipt')); return ['discarded' => true]; }); }
    public function suggestReceipt(Request $r) {
        app(MobileExpenseReceipts::class)->owned($this->actor($r), $this->tenant($r), $r->route('receipt'));
        // No configured/reviewed expense OCR contract yet: never fabricate extracted fields.
        abort(503, 'Die Beleganalyse ist noch nicht freigeschaltet. Bitte manuell erfassen.');
    }
    public function file(Request $r) {
        $d = $r->validate(['index' => 'nullable|integer|min:0|max:9']);
        return app(MemberDocumentService::class)->openExpense($this->actor($r), $this->tenant($r), (int) $r->route('line'), true, $d['index'] ?? 0);
    }
}
