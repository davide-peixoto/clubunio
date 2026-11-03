<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Expense, ExpenseLine, Tenant};
use App\ClubUnio\Services\{BankAccountService, ExpenseService, MemberAccessService, ModulePermissionService};
use Illuminate\Support\Facades\{Crypt, DB};

final class MobileExpenseData
{
    public function table(Tenant $tenant, string $table) {
        $alias = str_contains($table, ' as ') ? substr($table, strrpos($table, ' as ') + 4).'.' : '';
        return DB::connection('clubunio')->table('clubora.'.$table)->where($alias.'tenant_id', $tenant->id);
    }
    public function receipt(Account $actor, Tenant $tenant, Expense $e): array {
        $service = app(ExpenseService::class);
        $e->loadMissing(['member','teamSeason.team','season','round','status']);
        return ['SpesenID' => (int) $e->id, 'Revision' => (int) $e->revision, 'MitgliederID' => (int) $e->member_id,
            'TeamID' => (int) ($e->teamSeason?->team_id ?? 0), 'TeamSaisonID' => (int) $e->team_season_id,
            'BankkontoStaffID' => (int) $e->bank_account_id, '_TeamLabel' => $e->teamSeason?->team?->name ?? '',
            '_ReceiptTitle' => $e->teamSeason?->label ?? '', 'Saison' => $e->season?->name ?? '',
            'Vorname' => $e->member?->first_name ?? '', 'Nachname' => $e->member?->last_name ?? '',
            'Status' => $e->status?->code ?? '', 'Runde' => $e->round?->code ?? '', 'Bemerkungen' => $e->notes ?? '',
            'CanManagePositions' => $service->editable($actor, $tenant, $e),
            'CanAddPosition' => $service->editable($actor, $tenant, $e, 'create'),
            'CanDeletePosition' => $service->editable($actor, $tenant, $e, 'deactivate'),
            'AllowedStatusCodes' => array_values(array_diff($service->targets($actor, $tenant, $e), ['B']))];
    }
    public function line(Tenant $tenant, ExpenseLine $line, int $pending = 0): array {
        $line->loadMissing(['expenseType','gameType']);
        $files = array_values(array_filter(array_map('trim', explode(',', (string) $line->storage_reference))));
        return ['SpesenPositionenID' => (int) $line->id, 'SpesenID' => (int) $line->expense_id, 'Revision' => (int) $line->revision,
            'SpesenTypID' => (int) $line->expense_type_id, '_TypeLabel' => $line->expenseType?->name ?? '',
            'Spieltyp' => $line->gameType?->code ?? '', 'Gegner' => $line->opponent ?? '', 'Betrag' => (string) $line->amount,
            'BemerkungenPos' => $line->notes ?? '', 'AiBetragUebernommen' => null, 'PendingUploadCount' => $pending,
            ...($files ? ['FileUploadName' => ['url' => '', 'name' => $line->filename ?: 'Beleg', 'type' => $line->content_type ?? ''],
                'BelegLength' => $line->file_size, 'AttachmentCount' => count($files)] : [])];
    }
    public function detail(Account $actor, Tenant $tenant, int $id): array {
        $e = app(ExpenseService::class)->visible($actor, $tenant)->with(['activeLines.expenseType','activeLines.gameType'])->findOrFail($id);
        $pending = $this->table($tenant, 'document_uploads')->where('kind', 'expense')->where('state', 'pending')->whereIn('object_id', $e->activeLines->pluck('id'))
            ->groupBy('object_id')->selectRaw('object_id,count(*) as total')->pluck('total', 'object_id');
        $canManage = \App\ClubUnio\Services\BexioConnectionService::canManage($actor, $tenant);
        $retry = $this->table($tenant, 'document_uploads')->where('kind', 'expense')->where('state', 'pending')->whereIn('object_id', $e->activeLines->pluck('id'))->orderBy('id')->get(['id','created_by'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'can_retry' => (int) $u->created_by === (int) $actor->id || $canManage])->all();
        $bank = app(BankAccountService::class)->visible($actor, $tenant)->whereKey($e->bank_account_id)->first();
        $bankLabel = $bank ? $this->bank($actor, $tenant, $bank)['Label'] : '';
        return ['receipt' => $this->receipt($actor, $tenant, $e) + ['_BankLabel' => $bankLabel, 'PendingUploadCount' => (int) $pending->sum(), 'PendingUploads' => $retry],
            'lines' => $e->activeLines->map(fn ($l) => $this->line($tenant, $l, (int) ($pending[$l->id] ?? 0)))->all()];
    }
    public function bank(Account $actor, Tenant $tenant, object $bank): array {
        // Never widen bank visibility from technical lead/team-expense access.
        abort_unless(app(BankAccountService::class)->visible($actor, $tenant)->whereKey($bank->id)->exists(), 404);
        $institution = $this->table($tenant, 'banks')->where('id', $bank->bank_id)->first();
        $iban = Crypt::decryptString($bank->iban_encrypted);
        return ['BankkontoStaffID' => (int) $bank->id, 'MitgliederID' => (int) $bank->member_id, 'InstitutID' => (int) $bank->bank_id,
            'IBAN' => $iban, 'Institut' => $institution->name ?? '', 'Revision' => (int) $bank->revision,
            'Label' => trim(($institution->name ?? '').' · '.$iban)];
    }
    public function options(Account $actor, Tenant $tenant): array {
        $access = app(MemberAccessService::class);
        $own = $access->represented($actor, $tenant, true)->get(['id','first_name','last_name']);
        $members = $own->map(fn ($m) => ['id' => (int) $m->id, 'name' => trim($m->first_name.' '.$m->last_name)])->all();
        $teams = $this->table($tenant, 'team_seasons as ts')->where('ts.active', true)
            ->when(! app(ModulePermissionService::class)->allows($actor, $tenant, 'expenses', 'create'), fn ($q) => $q
                ->whereIn('ts.id', $this->table($tenant, 'team_staff')->where('active', true)->whereIn('member_id', $own->pluck('id'))->select('team_season_id')))
            ->join('clubora.teams as t', fn ($j) => $j->on('t.id','=','ts.team_id')->on('t.tenant_id','=','ts.tenant_id'))
            ->where('t.active', true);
        app(MobileSeasonScope::class)->teams($teams, $tenant, 'ts.season_id');
        $teams = $teams->orderBy('ts.label')->get(['ts.id','ts.label','ts.team_id','t.laundry_allowance'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'label' => $t->label, 'team_id' => (int) $t->team_id, 'laundry' => $t->laundry_allowance])->all();
        return ['members' => $members, 'teams' => $teams,
            'rounds' => $this->table($tenant, 'expense_rounds')->where('active', true)->orderBy('id')->get(['id','code','name'])->all(),
            'types' => $this->table($tenant, 'expense_types')->where('active', true)->orderBy('name')->get(['id','name','receipt_required','amount_source'])->all(),
            'game_types' => $this->table($tenant, 'game_types')->where('active', true)->orderBy('id')->get(['id','code','name'])->all(),
            'institutions' => $this->table($tenant, 'banks')->where('active', true)->orderBy('name')->get(['id','name','clearing_number'])->all(),
            'capabilities' => ['upload' => (bool) config('clubunio-mobile.uploads_enabled') && $tenant->slug === config('clubunio-integrations.azure.tenant_slug'),
                'ai' => in_array('expense-ocr-v1', array_map('trim', explode(',', substr((string) request()->header('X-ClubUnio-Mobile-Features', ''), 0, 512))), true)
                    && app(MobileExpenseOcr::class)->available($tenant),
                'create' => $own->isNotEmpty() && count($teams) > 0]];
    }
}
