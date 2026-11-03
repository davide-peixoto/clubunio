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
    public function absences(Request $r) {
        return response()->json(['data' => app(MobilePlanningData::class)->absences($r, $r->attributes->get('mobile.actor'), $r->attributes->get('mobile.tenant'))]);
    }

    public function saveAbsence(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $id = $r->route('absence') ? (int) $r->route('absence') : null;
            $delete = $r->isMethod('DELETE');
            // Scope the old record before accepting any client-supplied person or revision.
            $old = $id ? $p->absences($actor, $tenant)->where('id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            $d = $r->validate(['MitgliederID' => $delete ? 'prohibited' : 'required|integer|min:1',
                'DatumVon' => $delete ? 'prohibited' : 'required|date', 'DatumBis' => $delete ? 'prohibited' : 'required|date|after:DatumVon',
                'Absenzart' => $delete ? 'prohibited' : 'required|string|max:40', 'Bemerkung' => 'nullable|string|max:1000',
                'Revision' => $id ? 'required|integer|min:1' : 'prohibited']);
            abort_if($old && isset($d['MitgliederID']) && (int) $d['MitgliederID'] !== (int) $old->member_id, 422, 'Person einer Absenz nicht austauschbar.');
            $member = (int) ($old->member_id ?? $d['MitgliederID']);
            $snapshot = fn () => $p->table($tenant, 'team_participations')->where('member_id', $member)
                ->whereIn('event_id', $p->table($tenant, 'team_events')->where('ends_at', '>', now()->toIso8601String())->select('id'))
                ->pluck('source', 'event_id')->all();
            $before = $snapshot();
            $input = ['member_id' => $member, 'revision' => $d['Revision'] ?? 0];
            if (! $delete) {
                $from = Time::parse($d['DatumVon'], 'Europe/Zurich')->setTimezone('Europe/Zurich');
                $to = Time::parse($d['DatumBis'], 'Europe/Zurich')->setTimezone('Europe/Zurich');
                $input += ['starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(),
                    'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(),
                    'kind' => $d['Absenzart'], 'note' => $d['Bemerkung'] ?? ''];
            }
            $saved = $p->saveAbsence($actor, $tenant, $id, $input, $delete);
            $after = $snapshot();
            $applied = $restored = 0;
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $event) {
                if (($before[$event] ?? null) !== 'absence' && ($after[$event] ?? null) === 'absence') { $applied++; }
                if (($before[$event] ?? null) === 'absence' && ($after[$event] ?? null) !== 'absence') { $restored++; }
            }
            return ['success' => true, 'sync' => ['autoApplied' => $applied, 'autoRestored' => $restored, 'skippedLocked' => $saved['skipped']]]
                + ($delete ? ['removed_absence_id' => $id] : ['Absenz' => app(MobilePlanningData::class)->absence($actor, $tenant, (int) $saved['id'])]);
        });
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
        // The SVS flow permits an omitted deadline; participation then closes at start.
        if (empty($result['deadline_at']) && ! empty($result['starts_at'])) { $result['deadline_at'] = $result['starts_at']; }
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

    public function batch(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $d = $r->validate(['command_key' => 'required|uuid', 'events' => 'required|array|min:1|max:104', 'events.*' => 'required|array']);
            $p->authorizeOperation($actor, $tenant, 'create');
            $rows = [];
            foreach ($d['events'] as $row) {
                $input = $this->input(Request::create('/', 'POST', $row));
                ksort($input);
                $rows[] = $input;
            }
            $team = $rows[0]['team_season_id'] ?? 0;
            abort_unless($team && $p->teams($actor, $tenant, true, 'create')->where('id', $team)->exists(), 403);
            abort_if(count(array_unique(array_column($rows, 'team_season_id'))) !== 1, 422, 'Eine Serie gehoert zu einer Mannschaft.');
            abort_if(count(array_unique(array_column($rows, 'starts_at'))) !== count($rows), 422, 'Doppelte Serienzeitpunkte.');
            $fingerprint = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            $commands = $p->table($tenant, 'mobile_planning_commands')->where('account_id', $actor->id)->where('command_key', $d['command_key']);
            $old = (clone $commands)->first();
            if ($old) {
                abort_unless($old->operation === 'batch_create' && hash_equals($old->fingerprint, $fingerprint), 409, 'Der Auftrag gehoert zu anderen Daten.');
                $ids = json_decode($old->event_ids, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $ids = [];
                foreach ($rows as $input) { $ids[] = $p->saveEvent($actor, $tenant, null, $input); }
                $key = count($ids) > 1 ? (string) \Illuminate\Support\Str::uuid() : null;
                $p->table($tenant, 'team_events')->whereIn('id', $ids)->update(['series_key' => $key]);
                $p->table($tenant, 'mobile_planning_commands')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                    'command_key' => $d['command_key'], 'operation' => 'batch_create', 'fingerprint' => $fingerprint, 'event_ids' => json_encode($ids)]);
            }
            return ['success' => true, 'TeamTermine' => array_map(fn ($id) => app(MobilePlanningData::class)->event($actor, $tenant, (int) $id), $ids),
                'replayed' => (bool) $old];
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

    public function guest(Request $r)
    {
        return $this->write($r, function ($actor, $tenant, $p) use ($r) {
            $eventId = (int) $r->route('event');
            $id = $r->route('guest') ? (int) $r->route('guest') : null;
            $delete = $r->isMethod('DELETE');
            $function = $delete ? 'deactivate' : ($id ? 'update' : 'create');
            $event = $p->event($actor, $tenant, $eventId, true, $function);
            $old = $id ? $p->table($tenant, 'team_guests')->where('event_id', $eventId)->where('id', $id)->whereNull('deleted_at')->first() : null;
            abort_if($id && ! $old, 404);
            $d = $r->validate(['Vorname' => $id ? 'sometimes|required|string|max:100' : 'required|string|max:100',
                'Nachname' => $id ? 'sometimes|required|string|max:100' : 'required|string|max:100',
                'TeilnahmeStatus' => 'sometimes|required|string|max:40', 'AnwesenheitsStatus' => 'sometimes|required|string|max:40',
                'Kommentar' => 'nullable|string|max:1000', 'AnwesenheitsKommentar' => 'nullable|string|max:1000',
                'Revision' => $id ? 'required|integer|min:1' : 'prohibited']);
            $attendance = array_key_exists('AnwesenheitsStatus', $d) || array_key_exists('AnwesenheitsKommentar', $d);
            abort_if($attendance && ! $id, 422, 'Anwesenheit erst am bestehenden Gast bearbeiten.');
            abort_if($attendance && Time::parse($event->starts_at)->isFuture(), 409, 'Anwesenheit erst nach Terminbeginn.');
            $input = ['first_name' => $d['Vorname'] ?? $old?->first_name, 'last_name' => $d['Nachname'] ?? $old?->last_name,
                'status' => $d['TeilnahmeStatus'] ?? $old?->status ?? 'OFFEN',
                'attendance' => $d['AnwesenheitsStatus'] ?? $old?->attendance ?? 'OFFEN',
                'comment' => array_key_exists('Kommentar', $d) ? $d['Kommentar'] : ($old?->comment_encrypted ? Crypt::decryptString($old->comment_encrypted) : ''),
                'revision' => $d['Revision'] ?? 0];
            app(\App\ClubUnio\Services\PlanningGuestService::class)->save($actor, $tenant, $eventId, $id, $input, $delete);
            if ($attendance && ! $delete) {
                $p->table($tenant, 'team_guests')->where('event_id', $eventId)->where('id', $id)->update([
                    'attendance_comment_encrypted' => empty($d['AnwesenheitsKommentar']) ? null : Crypt::encryptString($d['AnwesenheitsKommentar']),
                    'attendance_updated_at' => now()->toIso8601String()]);
            }
            return ['success' => true, 'TeamTermin' => app(MobilePlanningData::class)->event($actor, $tenant, $eventId)];
        });
    }

}
