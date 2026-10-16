<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sabre\VObject\Component\VCalendar;

class PlanningCalendarService
{
    public function __construct(private TeamPlanningService $planning) {}

    public function visible(Account $actor, Tenant $tenant)
    {
        $p = $this->planning;
        $p->enter($actor, $tenant);

        return $p->table($tenant, 'team_events')->where(fn ($q) => $q
            ->whereIn('team_season_id', $p->teams($actor, $tenant)->select('id'))
            ->orWhereIn('id', $p->invitedEvents($actor, $tenant)->select('event_id')));
    }

    public function calendar(Account $actor, Tenant $tenant, array $input): array
    {
        $p = $this->planning;
        $p->enter($actor, $tenant);
        $filters = Validator::make($input, [
            'view' => ['nullable', Rule::in(['month', 'week', 'teams'])],
            'date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'team' => 'nullable|integer|min:1', 'season' => 'nullable|integer|min:1',
            'buses' => 'nullable|boolean',
            'kind' => 'nullable|string|max:40', 'status' => 'nullable|string|max:40', 'q' => 'nullable|string|max:100',
        ])->validate();
        $filters['view'] = $filters['view'] ?? 'month';
        $filters['date'] = $filters['date'] ?? now('Europe/Zurich')->toDateString();
        $anchor = Time::parse($filters['date'], 'Europe/Zurich');
        $month = $filters['view'] === 'month';
        $start = $month ? $anchor->startOfMonth()->startOfWeek() : $anchor->startOfWeek();
        $end = $month ? $anchor->endOfMonth()->startOfDay()->endOfWeek()->startOfDay()->addDay() : $start->addWeek();
        $visible = $this->visible($actor, $tenant);
        $teams = $p->table($tenant, 'team_seasons')->where(fn ($q) => $q
            ->whereIn('id', $p->teams($actor, $tenant)->select('id'))
            ->orWhereIn('id', (clone $visible)->select('team_season_id')))->orderByDesc('season_id')->orderBy('label')->get();
        $seasons = $p->table($tenant, 'seasons')->whereIn('id', $teams->pluck('season_id'))->orderByDesc('name')->get();
        $query = (clone $visible)->where('starts_at', '<', $end->toIso8601String())->where('ends_at', '>', $start->toIso8601String());
        if (! empty($filters['team'])) {
            $query->where('team_season_id', $filters['team']);
        }
        if (! empty($filters['season'])) {
            $query->whereIn('team_season_id', $teams->where('season_id', (int) $filters['season'])->pluck('id'));
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $query->where('title', 'ilike', '%'.$filters['q'].'%');
        }
        $events = $query->orderBy('starts_at')->orderBy('id')->limit(1001)->get();
        $busReservations = collect();
        $busNames = collect();
        $busTeams = collect();
        $busStatuses = collect();
        if (! empty($filters['buses'])) {
            $bus = app(BusReservationService::class);
            $busQuery = $bus->visible($actor, $tenant)->whereIn('status', ['ANGEFRAGT', 'GENEHMIGT'])
                ->where('starts_at', '<', $end->toIso8601String())->where('ends_at', '>', $start->toIso8601String());
            if (! empty($filters['team'])) {
                $busQuery->where('team_season_id', $filters['team']);
            }
            if (! empty($filters['season'])) {
                $busQuery->whereIn('team_season_id', $p->table($tenant, 'team_seasons')->where('season_id', $filters['season'])->select('id'));
            }
            if (! empty($filters['q'])) {
                $busQuery->where(fn ($q) => $q->where('location', 'ilike', '%'.$filters['q'].'%')
                    ->orWhereIn('bus_id', $bus->table($tenant, 'buses')->where('name', 'ilike', '%'.$filters['q'].'%')->select('id'))
                    ->orWhereIn('team_season_id', $p->table($tenant, 'team_seasons')->where('label', 'ilike', '%'.$filters['q'].'%')->select('id')));
            }
            $busReservations = $busQuery->orderBy('starts_at')->orderBy('id')->limit(1001)->get();
            if ($busReservations->count() > 1000) {
                throw ValidationException::withMessages(['team' => 'Zu viele Busreservierungen. Bitte die Auswahl eingrenzen.']);
            }
            $busNames = $bus->table($tenant, 'buses')->whereIn('id', $busReservations->pluck('bus_id'))->pluck('name', 'id');
            $busTeams = $p->table($tenant, 'team_seasons')->whereIn('id', $busReservations->pluck('team_season_id'))->pluck('label', 'id');
            $busStatuses = $bus->table($tenant, 'bus_statuses')->pluck('name', 'code');
        }
        if ($events->count() > 1000) {
            throw ValidationException::withMessages(['team' => 'Zu viele Termine. Bitte die Mannschaft oder Saison eingrenzen.']);
        }
        // Conflict checks deliberately ignore display filters, but never access restrictions.
        $conflicts = [];
        if ($events->isNotEmpty()) {
            $candidates = (clone $visible)->where('status', 'AKTIV')->whereIn('team_season_id', $events->pluck('team_season_id'))
                ->where('starts_at', '<', Time::parse($events->max('ends_at'))->toIso8601String())
                ->where('ends_at', '>', Time::parse($events->min('starts_at'))->toIso8601String())->limit(5001)->get();
            if ($candidates->count() > 5000) {
                throw ValidationException::withMessages(['team' => 'Zu viele Vergleichstermine. Bitte den Zeitraum und die Mannschaft eingrenzen.']);
            }
            $conflicts = app(PlanningCalendarTimeline::class)->conflicts($events, $candidates);
        }
        $days = app(PlanningCalendarTimeline::class)->days($start, $end, $events, $busReservations);
        $previous = $month ? $anchor->subMonthNoOverflow() : $anchor->subWeek();
        $next = $month ? $anchor->addMonthNoOverflow() : $anchor->addWeek();

        return compact('filters', 'anchor', 'start', 'end', 'teams', 'seasons', 'events', 'days', 'previous', 'next', 'conflicts', 'busReservations', 'busNames', 'busTeams', 'busStatuses');
    }

    public function export(Account $actor, Tenant $tenant, array $input): string
    {
        $data = $this->calendar($actor, $tenant, $input);
        $calendar = new VCalendar(['PRODID' => '-//Clubora//Teamplanung//DE', 'CALSCALE' => 'GREGORIAN']);
        foreach ($data['events'] as $event) {
            $team = $data['teams']->firstWhere('id', $event->team_season_id);
            $calendar->add('VEVENT', [
                'UID' => 'clubora-'.$tenant->id.'-'.$event->id.'@clubora',
                'DTSTAMP' => Time::parse($event->updated_at)->utc(),
                'LAST-MODIFIED' => Time::parse($event->updated_at)->utc(),
                'DTSTART' => Time::parse($event->starts_at)->utc(), 'DTEND' => Time::parse($event->ends_at)->utc(),
                'SEQUENCE' => (int) $event->revision, 'SUMMARY' => $event->title,
                'LOCATION' => $event->location ?? '', 'DESCRIPTION' => $team?->label ?? '',
                'STATUS' => $event->status === 'ABGESAGT' ? 'CANCELLED' : 'CONFIRMED',
                'URL' => route('clubunio.planning.show', [$tenant->slug, $event->id]),
            ]);
        }

        foreach ($data['busReservations'] as $reservation) {
            $calendar->add('VEVENT', [
                'UID' => 'clubora-bus-'.$tenant->id.'-'.$reservation->id.'@clubora',
                'DTSTAMP' => Time::parse($reservation->updated_at)->utc(),
                'LAST-MODIFIED' => Time::parse($reservation->updated_at)->utc(),
                'DTSTART' => Time::parse($reservation->starts_at)->utc(), 'DTEND' => Time::parse($reservation->ends_at)->utc(),
                'SEQUENCE' => (int) $reservation->revision,
                'SUMMARY' => 'Bus: '.($data['busNames'][$reservation->bus_id] ?? 'Reservation'),
                'LOCATION' => $reservation->location ?? '',
                'DESCRIPTION' => $data['busTeams'][$reservation->team_season_id] ?? '',
                'STATUS' => $reservation->status === 'ANGEFRAGT' ? 'TENTATIVE' : 'CONFIRMED',
                'URL' => route('clubunio.bus.show', [$tenant->slug, $reservation->id]),
            ]);
        }

        return $calendar->serialize();
    }
}
