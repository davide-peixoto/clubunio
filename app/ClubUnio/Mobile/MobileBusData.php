<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BusReservationService, MemberAccessService, ModulePermissionService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;

final class MobileBusData
{
    public function teams(Account $actor, Tenant $tenant)
    {
        return app(MobileSeasonScope::class)->teams(app(BusReservationService::class)->reservableTeams($actor, $tenant), $tenant);
    }
    public function visible(Account $actor, Tenant $tenant)
    {
        $bus = app(BusReservationService::class);
        // Preserve owner visibility independently of current staff/create permission.
        $seasonTeams = app(MobileSeasonScope::class)->teams($bus->table($tenant, 'team_seasons')->where('active', true), $tenant);
        return $bus->visible($actor, $tenant)->whereIn('team_season_id', $seasonTeams->select('id'));
    }
    private function readRights(Account $actor, Tenant $tenant): array
    {
        $permissions = app(ModulePermissionService::class);
        return ['approve' => $permissions->allows($actor, $tenant, 'bus', 'approve'), 'cancel' => $permissions->allows($actor, $tenant, 'bus', 'cancel'),
            'own' => app(MemberAccessService::class)->represented($actor, $tenant, true)->pluck('id')->all()];
    }
    public function row(Account $actor, Tenant $tenant, object $row, array $labels = [], ?array $rights = null): array
    {
        $bus = app(BusReservationService::class); $rights ??= $this->readRights($actor, $tenant);
        $future = Time::parse($row->ends_at)->isFuture();
        $live = in_array($row->status, ['ANGEFRAGT', 'GENEHMIGT', 'UNBEKANNT'], true);
        $approve = $future && in_array($row->status, ['ANGEFRAGT', 'UNBEKANNT'], true) && $rights['approve'];
        $cancel = $future && $live && ($rights['cancel'] || ($row->status === 'ANGEFRAGT' && in_array((int) $row->member_id, $rights['own'], true)));
        return ['ResID' => (int) $row->id, 'Revision' => (int) $row->revision, 'BusID' => (int) $row->bus_id,
            'TeamSaisonID' => (int) $row->team_season_id, 'BusText' => $labels['buses'][$row->bus_id] ?? $bus->table($tenant, 'buses')->where('id', $row->bus_id)->value('name') ?? '',
            'TeamSaisonText' => $labels['teams'][$row->team_season_id] ?? $bus->table($tenant, 'team_seasons')->where('id', $row->team_season_id)->value('label') ?? '',
            'Ort' => $row->location ?? '', 'Descr' => $row->description ?? '', 'EntscheidBemerkung' => $row->decision_note,
            'ResVon' => Time::parse($row->starts_at)->setTimezone('Europe/Zurich')->toIso8601String(),
            'ResBis' => Time::parse($row->ends_at)->setTimezone('Europe/Zurich')->toIso8601String(), 'ReservierungsStatus' => $row->status,
            'CanApprove' => $approve, 'CanReject' => $approve, 'CanCancel' => $cancel];
    }
    public function index(Request $request, Account $actor, Tenant $tenant): array
    {
        $d = $request->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|in:ANGEFRAGT,GENEHMIGT,ABGELEHNT,STORNIERT,UNBEKANNT', 'period' => 'nullable|in:past,upcoming']);
        $base = $this->visible($actor, $tenant)->where('ends_at', ($d['period'] ?? 'upcoming') === 'past' ? '<=' : '>', now()->toIso8601String());
        $counts = (clone $base)->selectRaw('status,count(*) AS count')->groupBy('status')->pluck('count', 'status')->map(fn ($n) => (int) $n)->all();
        $query = (clone $base)->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']));
        $total = (clone $query)->count();
        $rows = $query->orderBy('starts_at')->orderBy('id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 50)->get();
        $bus = app(BusReservationService::class);
        $rights = $this->readRights($actor, $tenant);
        $labels = ['buses' => $bus->table($tenant, 'buses')->whereIn('id', $rows->pluck('bus_id'))->pluck('name', 'id')->all(),
            'teams' => $bus->table($tenant, 'team_seasons')->whereIn('id', $rows->pluck('team_season_id'))->pluck('label', 'id')->all()];
        return ['reservations' => $rows->map(fn ($row) => $this->row($actor, $tenant, $row, $labels, $rights))->all(), 'total' => $total,
            'counts' => (object) $counts, 'capabilities' => $this->capabilities($actor, $tenant)];
    }
    public function capabilities(Account $actor, Tenant $tenant): array
    {
        $permissions = app(ModulePermissionService::class); $access = app(MemberAccessService::class);
        $global = $permissions->allows($actor, $tenant, 'bus', 'create');
        return ['create' => $this->teams($actor, $tenant)->exists() && ($global || $access->represented($actor, $tenant, true)->exists()),
            'approve' => $permissions->allows($actor, $tenant, 'bus', 'approve'), 'choose_applicant' => $global || $access->represented($actor, $tenant, true)->limit(2)->count() > 1];
    }
    public function options(Account $actor, Tenant $tenant): array
    {
        $bus = app(BusReservationService::class); $bus->enter($actor, $tenant);
        $own = app(MemberAccessService::class)->represented($actor, $tenant, true)->get(['id', 'first_name', 'last_name']);
        return ['buses' => $bus->table($tenant, 'buses')->where('active', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'teams' => $this->teams($actor, $tenant)->orderBy('label')->orderBy('id')->get(['id', 'label'])->all(),
            'default_applicant_id' => $own->count() === 1 ? (int) $own->first()->id : null, 'capabilities' => $this->capabilities($actor, $tenant)];
    }
    public function members(Request $request, Account $actor, Tenant $tenant): array
    {
        $bus = app(BusReservationService::class); $bus->authorizeOperation($actor, $tenant, 'create');
        $d = $request->validate(['q' => 'nullable|string|max:100']); $q = trim($d['q'] ?? '');
        $access = app(MemberAccessService::class);
        $members = app(ModulePermissionService::class)->allows($actor, $tenant, 'bus', 'create') ? $access->activeMembers($tenant) : $access->represented($actor, $tenant, true);
        return $members->when($q !== '', fn ($b) => $b->where(fn ($b) => $b->where('first_name', 'ilike', '%'.$q.'%')->orWhere('last_name', 'ilike', '%'.$q.'%')))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(20)->get(['id', 'first_name', 'last_name'])
            ->map(fn ($member) => ['id' => (int) $member->id, 'name' => trim($member->first_name.' '.$member->last_name)])->all();
    }
}
