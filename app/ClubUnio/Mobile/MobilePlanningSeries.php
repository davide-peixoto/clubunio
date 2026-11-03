<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** SVS's "Serie ab hier" flow with current ClubUnio exception/lock policies. */
final class MobilePlanningSeries
{
    public function __construct(private TeamPlanningService $planning) {}

    private function rows(Tenant $tenant, object $event)
    {
        return $this->planning->table($tenant, 'team_events')->where('team_season_id', $event->team_season_id)
            ->where('series_key', $event->series_key)->orderBy('id')->get();
    }

    private function fingerprint(Tenant $tenant, object $event, $rows): string
    {
        return hash('sha256', json_encode([$tenant->id, $event->team_season_id, $event->series_key, $rows->all()], JSON_THROW_ON_ERROR));
    }

    public function revision(Tenant $tenant, object $event): ?string
    {
        return $event->series_key ? $this->fingerprint($tenant, $event, $this->rows($tenant, $event)) : null;
    }

    // Caller holds the tenant/session transaction. Every save rechecks current rights.
    public function update(Account $actor, Tenant $tenant, object $event, array $input, string $revision): array
    {
        $p = $this->planning;
        $p->authorizeOperation($actor, $tenant, 'update');
        $p->conflict($event, $input);
        abort_unless($event->series_key, 422, 'Termin gehoert keiner Serie an.');
        abort_if($event->status !== 'AKTIV', 409, 'Abgesagten Termin nicht als Serienvorlage verwenden.');
        abort_unless((int) ($input['team_season_id'] ?? 0) === (int) $event->team_season_id, 422, 'Mannschaft einer Serie nicht austauschbar.');
        $rows = $this->rows($tenant, $event);
        abort_unless(hash_equals($this->fingerprint($tenant, $event, $rows), $revision), 409, 'Die Serie hat sich geaendert. Bitte neu laden.');
        Validator::make($input, ['starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'required|date_format:Y-m-d\TH:i|after:starts_at',
            'deadline_at' => 'required|date_format:Y-m-d\TH:i|before_or_equal:starts_at'])->validate();
        $start = Time::parse($input['starts_at'], 'Europe/Zurich');
        $duration = $start->diffInSeconds(Time::parse($input['ends_at'], 'Europe/Zurich'));
        $deadlineOffset = Time::parse($input['deadline_at'], 'Europe/Zurich')->diffInSeconds($start);
        $changes = [];
        $skipped = $unchanged = 0;
        foreach ($rows as $row) {
            if (Time::parse($row->starts_at)->lt(Time::parse($event->starts_at))) { continue; }
            if ($row->series_exception || $p->locked($row)) { $skipped++; continue; }
            $next = $input;
            $next['revision'] = (int) $row->revision;
            $nextStart = Time::parse($row->starts_at)->tz('Europe/Zurich')->setTime($start->hour, $start->minute);
            $times = ['starts_at' => $nextStart, 'ends_at' => $nextStart->addSeconds($duration), 'deadline_at' => $nextStart->subSeconds($deadlineOffset)];
            foreach ($times as $field => $time) {
                if ($field !== 'ends_at' && $time->isPast()) {
                    throw ValidationException::withMessages(['DatumVon' => 'Termin oder Frist wuerde in die Vergangenheit gelegt.']);
                }
                $next[$field] = $time->format('Y-m-d\TH:i');
            }
            $different = false;
            foreach ($next as $field => $value) {
                $before = isset($times[$field]) && $row->$field ? Time::parse($row->$field)->tz('Europe/Zurich')->format('Y-m-d\TH:i') : $row->$field;
                $different = $different || (string) $before !== (string) $value;
            }
            if (! $different) { $unchanged++; continue; }
            $changes[] = [$row, $next];
        }
        foreach ($changes as [$row, $next]) { $p->saveEvent($actor, $tenant, (int) $row->id, $next, true); }
        return ['changed' => count($changes), 'skipped' => $skipped, 'unchanged' => $unchanged];
    }
}
