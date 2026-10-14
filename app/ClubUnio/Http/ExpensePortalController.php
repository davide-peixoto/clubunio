<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\ExpenseRound;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\GameType;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpensePortalController
{
    private function authorize(Request $request, Tenant $tenant): void
    {
        abort_unless(app(MemberAccessService::class)->canEnter($request->user('portal'), $tenant), 403);
    }

    public function index(Request $request, Tenant $tenant, ExpenseService $service)
    {
        $this->authorize($request, $tenant);
        $data = $request->validate(['status' => 'nullable|integer', 'q' => 'nullable|string|max:120']);
        $q = trim($data['q'] ?? '');
        $expenses = $service->visible($request->user('portal'), $tenant)->with(['member', 'teamSeason', 'round', 'status'])->withSum('activeLines', 'amount')
            ->when($data['status'] ?? null, fn ($q, $id) => $q->where('status_id', $id))
            ->when($q !== '', fn ($query) => $query->whereHas('teamSeason', fn ($query) => $query->where('label', 'ilike', '%'.$q.'%')))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $statuses = ExpenseStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();

        return response()->view('clubunio.portal.expenses', compact('tenant', 'expenses', 'statuses', 'q') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Tenant $tenant, int $expense, ExpenseService $service)
    {
        $this->authorize($request, $tenant);
        $e = $service->visible($request->user('portal'), $tenant)->with(['member', 'teamSeason.team', 'status', 'round', 'activeLines.expenseType', 'activeLines.gameType'])->findOrFail($expense);
        $editable = $service->editable($request->user('portal'), $tenant, $e);
        $statuses = ExpenseStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereIn('code', $service->targets($request->user('portal'), $tenant, $e))->get();
        $types = ExpenseType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();
        $gameTypes = GameType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();

        return response()->view('clubunio.portal.expense', compact('tenant', 'e', 'editable', 'statuses', 'types', 'gameTypes') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, Tenant $tenant)
    {
        $this->authorize($request, $tenant);
        $members = app(MemberAccessService::class)->represented($request->user('portal'), $tenant, true)->get();
        $member = $members->first();
        abort_unless($member, 403);
        $teams = TeamSeason::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereIn('id', DB::connection('clubunio')->table('clubora.team_staff')
            ->where('tenant_id', $tenant->id)->where('active', true)->where('member_id', $member->id)->select('team_season_id'))->orderByDesc('season_id')->get();
        $banks = MemberBankAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('active', true)->with('bank')->get();
        $rounds = ExpenseRound::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->get();

        return response()->view('clubunio.portal.expense-create', compact('tenant', 'member', 'teams', 'banks', 'rounds') + ['tenants' => collect([$tenant])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Tenant $tenant, ExpenseService $service)
    {
        $this->authorize($request, $tenant);
        $e = $service->save($request->user('portal'), $tenant, null, $request->only(['member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes']));

        return redirect()->route('clubunio.portal.expense', ['tenant' => $tenant->slug, 'expense' => $e->id]);
    }

    public function transition(Request $request, Tenant $tenant, int $expense, ExpenseService $service)
    {
        $this->authorize($request, $tenant);
        $service->transition($request->user('portal'), $tenant, $expense, $request->only(['revision', 'status_id', 'reason']));

        return redirect()->route('clubunio.portal.expense', ['tenant' => $tenant->slug, 'expense' => $expense])->with('saved', true);
    }

    public function line(Request $request, Tenant $tenant, int $expense, ExpenseService $service, ?int $line = null)
    {
        $this->authorize($request, $tenant);
        $service->saveLine($request->user('portal'), $tenant, $expense, $line, $request->only(['revision', 'expense_revision', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes', 'active']));

        return redirect()->route('clubunio.portal.expense', ['tenant' => $tenant->slug, 'expense' => $expense])->with('saved', true);
    }

    public function document(Request $request, Tenant $tenant, int $line, MemberDocumentService $service)
    {
        $this->authorize($request, $tenant);
        $data = $request->validate(['index' => 'nullable|integer|min:0|max:100']);

        return $service->openExpense($request->user('portal'), $tenant, $line, $request->boolean('download'), (int) ($data['index'] ?? 0));
    }
}
