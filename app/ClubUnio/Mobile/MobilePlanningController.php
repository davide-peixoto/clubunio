<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable as Time;

final class MobilePlanningController
{
    public function teams(Request $r) {
        return response()->json(['data' => app(MobilePlanningData::class)->teams($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }
    public function events(Request $r) {
        return response()->json(['data' => app(MobilePlanningData::class)->events($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }
    public function event(Request $r) {
        return response()->json(['data' => ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'), (int) $r->route('event'))]]);
    }
    private function write(Request $r, callable $operation)
    {
        return DB::connection('clubunio')->transaction(function () use ($r, $operation) {
            $tenant = $r->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $r->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($r);
            app(MobileAccess::class)->enter($actor, $tenant);
            return response()->json(['data' => $operation($actor, $tenant, app(TeamPlanningService::class))]);
        });
    }

    private function input(Request $r, ?object $old = null): array
    {
        $d = $r->validate(['TeamSaisonID' => 'nullable|integer|min:1', 'Titel' => 'sometimes|required|string|max:255',
            'Terminart' => 'sometimes|required|string|max:40', 'TeilnahmeModus' => 'sometimes|required|string|max:40',
            'DatumVon' => 'sometimes|required|date', 'DatumBis' => 'sometimes|required|date', 'AnmeldeFrist' => 'sometimes|required|date',
            'Ort' => 'nullable|string|max:255', 'Beschreibung' => 'nullable|string|max:4000', 'MaxTeilnehmer' => 'nullable|integer|min:1|max:1000',
            'Revision' => $old ? 'required|integer|min:1' : 'prohibited', 'SerieID' => 'prohibited', 'scope' => 'nullable|in:single']);
        $map = ['TeamSaisonID' => 'team_season_id', 'Titel' => 'title', 'Terminart' => 'kind', 'TeilnahmeModus' => 'mode',
            'Ort' => 'location', 'Beschreibung' => 'description', 'MaxTeilnehmer' => 'capacity', 'Revision' => 'revision'];
        $result = $old ? array_intersect_key((array) $old, array_flip(['team_season_id', 'title', 'kind', 'mode', 'location', 'description', 'capacity', 'starts_at', 'ends_at', 'deadline_at', 'status', 'revision'])) : ['status' => 'AKTIV'];
        foreach ($map as $from => $to) { if (array_key_exists($from, $d)) { $result[$to] = $d[$from]; } }
        foreach (['DatumVon' => 'starts_at', 'DatumBis' => 'ends_at', 'AnmeldeFrist' => 'deadline_at'] as $from => $to) {
            if (isset($d[$from])) { $result[$to] = Time::parse($d[$from], 'Europe/Zurich')->setTimezone('Europe/Zurich')->format('Y-m-d\TH:i'); }
            elseif (isset($result[$to])) { $result[$to] = Time::parse($result[$to])->setTimezone('Europe/Zurich')->format('Y-m-d\TH:i'); }
        }
        return $result;
    }

    public function save(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $id = $r->route('event') ? (int) $r->route('event') : null;
            $old = $id ? $p->event($actor, $tenant, $id, true) : null;
            $input = $this->input($r, $old);
            $id = $p->saveEvent($actor, $tenant, $id, $input);
            return ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, $id)];
        });
    }

    public function cancel(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $r->validate(['Revision' => 'required|integer|min:1', 'scope' => 'nullable|in:single']);
            $old = $p->event($actor, $tenant, (int) $r->route('event'), true, 'cancel');
            $input = $this->input($r, $old);
            $input['status'] = 'ABGESAGT';
            $p->saveEvent($actor, $tenant, (int) $old->id, $input);
            return ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, (int) $old->id), 'pushWarning' => ! config('clubunio-mobile.push_enabled')];
        });
    }

    public function participate(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $d = $r->validate(['MitgliederID' => 'required|integer|min:1', 'TeilnahmeStatus' => 'required|string|max:40',
                'Kommentar' => 'nullable|string|max:1000', 'Revision' => 'required|integer|min:0']);
            $id = (int) $r->route('event');
            $event = $p->event($actor, $tenant, $id);
            $manager = $p->teams($actor, $tenant, true)->where('id', $event->team_season_id)->exists();
            $old = $p->table($tenant, 'team_participations')->where('event_id', $id)->where('member_id', $d['MitgliederID'])->first();
            $input = ['status' => $d['TeilnahmeStatus'], 'comment' => $d['Kommentar'] ?? '', 'revision' => $d['Revision']];
            if ($manager) { $input['attendance'] = $old->attendance ?? 'OFFEN'; }
            $p->participate($actor, $tenant, $id, (int) $d['MitgliederID'], $input);
            return ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, $id)];
        });
    }

    public function attendance(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $d = $r->validate(['MitgliederID' => 'required|integer|min:1', 'AnwesenheitsStatus' => 'required|string|max:40',
                'AnwesenheitsKommentar' => 'nullable|string|max:1000', 'Revision' => 'required|integer|min:0']);
            $id = (int) $r->route('event');
            $event = $p->event($actor, $tenant, $id, true);
            abort_if(Time::parse($event->starts_at)->isFuture(), 409, 'Anwesenheit erst nach Terminbeginn.');
            $old = $p->table($tenant, 'team_participations')->where('event_id', $id)->where('member_id', $d['MitgliederID'])->first();
            $p->participate($actor, $tenant, $id, (int) $d['MitgliederID'], [
                'revision' => $d['Revision'], 'status' => $old->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN'),
                'attendance' => $d['AnwesenheitsStatus'],
                'comment' => $old?->comment_encrypted ? Crypt::decryptString($old->comment_encrypted) : '',
            ]);
            $p->table($tenant, 'team_participations')->where('event_id', $id)->where('member_id', $d['MitgliederID'])->update([
                'attendance_comment_encrypted' => empty($d['AnwesenheitsKommentar']) ? null : Crypt::encryptString($d['AnwesenheitsKommentar']),
                'attendance_updated_at' => now()->toIso8601String(),
            ]);
            return ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, $id)];
        });
    }

}
