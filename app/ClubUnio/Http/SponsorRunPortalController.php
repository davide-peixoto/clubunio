<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\SponsorRunParticipant;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\SponsorRunPublicService;
use App\ClubUnio\Services\SponsorRunService;
use App\ClubUnio\Services\SponsorRunMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SponsorRunPortalController
{
    private function actor(Request $request)
    {
        $actor = $request->user('portal');
        abort_unless($actor, 403);

        return $actor;
    }

    public function index(Request $request, Tenant $tenant, SponsorRunService $service)
    {
        $filters = $request->validate(['season_id' => 'nullable|integer|min:1', 'scope' => 'nullable|in:mine,teams,helper,manage,all', 'search' => 'nullable|string|max:100']);
        $filters['scope'] ??= 'mine';
        $events = $service->events($this->actor($request), $tenant, $filters)->paginate(20)->withQueryString();
        $seasons = $service->db()->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('active', true)->orderByDesc('id')->pluck('name', 'id')->all();

        return $this->view('clubunio.portal.sponsor-runs', compact('tenant', 'events', 'seasons', 'filters'));
    }

    public function show(Request $request, Tenant $tenant, int $event, SponsorRunService $service)
    {
        $filters = $request->validate(['scope' => 'nullable|in:mine,teams,helper,manage,all', 'search' => 'nullable|string|max:100', 'team_season_id' => 'nullable|integer|min:1', 'participant' => 'nullable|integer|min:1']);
        $filters['scope'] ??= 'all';
        $actor = $this->actor($request);
        $participants = $service->participants($actor, $tenant, $event, $filters)->paginate(20)->withQueryString();
        abort_unless($participants->total() > 0 || $service->allows($actor, $tenant), 404);
        $event = $service->event($tenant, $event);
        $summaries = $service->summaries($actor, $tenant, $participants->pluck('id')->all());
        $rights = $service->capabilities($actor, $tenant, $event->id);
        $teams = $service->db()->table('clubora.sponsor_run_event_teams as et')->join('clubora.team_seasons as t', fn ($j) => $j->on('t.id', '=', 'et.team_season_id')->on('t.tenant_id', '=', 'et.tenant_id'))
            ->where('et.tenant_id', $tenant->id)->where('et.event_id', $event->id)->orderBy('t.label')->pluck('t.label', 't.id')->all();
        $selected = null;
        $pledges = null;
        $canAdd = false;
        $canShare = false;
        if ($filters['participant'] ?? null) {
            abort_unless($service->participants($actor, $tenant, $event->id)->where('p.id', $filters['participant'])->exists(), 403);
            $selected = SponsorRunParticipant::where('tenant_id', $tenant->id)->where('event_id', $event->id)->findOrFail($filters['participant']);
            $pledges = $service->canViewPledges($actor, $tenant, $selected) ? $service->pledges($actor, $tenant, $selected->id)->paginate(20, ['*'], 'pledges')->withQueryString() : null;
            $canAdd = $selected->required && $event->status_code === 'open' && $event->registration_deadline->toDateString() >= $service->today() && ! $selected->approved_at && $service->canAddPledge($actor, $tenant, $selected);
            $canShare = $service->allows($actor, $tenant, 'send') || app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($selected->member_id)->exists();
        }
        $requestKey = (string) Str::uuid();

        return $this->view('clubunio.portal.sponsor-run', compact('tenant', 'event', 'participants', 'summaries', 'rights', 'teams', 'selected', 'pledges', 'canAdd', 'canShare', 'requestKey', 'filters'));
    }

    public function pledge(Request $request, Tenant $tenant, int $participant, SponsorRunService $service)
    {
        $service->addPledge($this->actor($request), $tenant, $participant, SponsorRunMoney::fromWeb($request->all()));

        return back()->with('sponsor_run_message', 'Sponsorenzusage gespeichert.');
    }

    public function operations(Request $request, Tenant $tenant, int $participant, SponsorRunService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:checkin,laps,approve', 'checked_in' => 'nullable|boolean', 'laps' => 'nullable|integer|min:0|max:10000']);
        if ($data['action'] === 'approve') {
            $request->validate(['confirm_final' => 'required|accepted']);
        }
        $service->operations($this->actor($request), $tenant, $participant, (int) $data['revision'], $data['action'], $data['action'] === 'checkin' ? (bool) ($data['checked_in'] ?? false) : ($data['laps'] ?? null));

        return back()->with('sponsor_run_message', 'Erfassung gespeichert.');
    }

    public function share(Request $request, Tenant $tenant, int $participant, SponsorRunPublicService $service)
    {
        $data = $request->validate(['action' => 'required|in:show,create,rotate,revoke', 'alias' => 'nullable|string|max:80', 'consent' => 'nullable|accepted']);
        $result = $service->share($this->actor($request), $tenant, $participant, $data['action'], $data);

        return back()->with('sponsor_run_share', $result)->with('sponsor_run_message', $result['active'] ? 'Sponsorenlink bereit.' : 'Sponsorenlink nicht aktiv.');
    }

    private function view(string $view, array $data)
    {
        return response()->view($view, $data + ['tenants' => collect([$data['tenant']])])->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
