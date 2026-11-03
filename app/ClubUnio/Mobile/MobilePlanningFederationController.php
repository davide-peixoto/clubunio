<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{SfvPlanningService, TeamPlanningService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobilePlanningFederationController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobilePlanningReady::class]; }

    public function index(Request $request)
    {
        return response()->json(['data' => app(MobilePlanningFederationData::class)->index($request,
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }

    public function accept(Request $request)
    {
        return DB::connection('clubunio')->transaction(function () use ($request) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            $p = app(TeamPlanningService::class);
            $p->authorizeOperation($actor, $tenant, 'sync');
            $d = $request->validate(['TeamSaisonID' => 'required|integer|min:1', 'SFVRevision' => 'required|integer|min:1',
                'EventRevision' => 'required|integer|min:0', 'TeilnahmeModus' => 'required|in:ANMELDEN,ABMELDEN',
                'MaxTeilnehmer' => 'nullable|integer|min:1|max:1000', 'Ort' => 'nullable|string|max:255',
                'Beschreibung' => 'nullable|string|max:4000', 'AnmeldeFrist' => 'nullable|date']);
            $snapshot = app(MobilePlanningFederationData::class)->query($actor, $tenant)
                ->where('sfv.match_id', (int) $request->route('match'))->where('ts.id', $d['TeamSaisonID'])->first();
            abort_unless($snapshot, 404);
            abort_if($snapshot->event_deleted_at !== null, 409, 'Dieser SFV-Termin wurde entfernt.');
            abort_unless($snapshot->starts_at, 422, 'Spielbeginn beim SFV noch unbekannt.');
            $match = $p->table($tenant, 'sfv_schedule')->where('id', $snapshot->schedule_id)->first();
            $old = $snapshot->event_id ? $p->table($tenant, 'team_events')->where('id', $snapshot->event_id)->first() : null;
            $start = Time::parse($match->starts_at)->setTimezone('Europe/Zurich');
            $duration = $old ? max(1, Time::parse($old->starts_at)->diffInMinutes(Time::parse($old->ends_at))) : 120;
            $kind = $old->kind ?? $p->table($tenant, 'planning_options')->where('category', 'kind')->where('active', true)
                ->whereRaw('lower(name) = ?', [mb_strtolower($match->match_type ?? '')])->value('code') ?? 'MEISTERSCHAFT';
            $deadline = isset($d['AnmeldeFrist']) ? Time::parse($d['AnmeldeFrist'], 'Europe/Zurich')->setTimezone('Europe/Zurich')
                : ($old?->deadline_at ? Time::parse($old->deadline_at)->setTimezone('Europe/Zurich') : $start);
            $input = ['team_season_id' => $d['TeamSaisonID'], 'sfv_revision' => $d['SFVRevision'], 'event_revision' => $d['EventRevision'],
                'title' => $match->title, 'kind' => $kind, 'starts_at' => $start->format('Y-m-d\TH:i'),
                'ends_at' => $start->addMinutes($duration)->format('Y-m-d\TH:i'), 'deadline_at' => $deadline->format('Y-m-d\TH:i'),
                'mode' => $d['TeilnahmeModus'], 'capacity' => $d['MaxTeilnehmer'] ?? $old?->capacity ?? 18,
                'status' => $old->status ?? 'AKTIV', 'location' => $d['Ort'] ?? $old?->location ?? $match->location,
                'description' => $d['Beschreibung'] ?? $old?->description ?? ''];
            $id = app(SfvPlanningService::class)->import($actor, $tenant, (int) $snapshot->schedule_id, $input);
            return response()->json(['data' => ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, $id)]]);
        });
    }
}
