<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanningSeriesService
{
    public function __construct(private TeamPlanningService $planning) {}

    public function preview(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        $p = $this->planning;
        $p->authorizeOperation($actor, $tenant, 'update');
        $event = $p->event($actor, $tenant, $id, true);
        abort_unless($event->series_key, 404);
        $data = Validator::make($input, ['from' => 'required|date_format:Y-m-d', 'shift_minutes' => 'required|integer|min:-1440|max:1440',
            'location' => 'nullable|string|max:255', 'change_location' => 'nullable|boolean', 'cancel' => 'nullable|boolean',
            'change_title' => 'nullable|boolean', 'title' => 'required_if:change_title,1|nullable|string|max:255',
            'change_capacity' => 'nullable|boolean', 'capacity' => 'nullable|integer|min:1|max:1000'])->validate();
        if ($data['cancel'] ?? false) {
            $p->authorizeOperation($actor, $tenant, 'cancel');
        }
        $rows = $p->table($tenant, 'team_events')->where('series_key', $event->series_key)->where('team_season_id', $event->team_season_id)->orderBy('starts_at')->get();
        $changes = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if ($row->series_exception || $p->locked($row) || Time::parse($row->starts_at)->tz('Europe/Zurich')->toDateString() < $data['from']) {
                $skipped++;

                continue;
            }
            $next = collect((array) $row)->only(['team_season_id', 'title', 'kind', 'mode', 'status', 'capacity', 'location', 'description', 'revision'])->all();
            $beforeTimes = [];
            foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
                if (! $row->$field) {
                    throw ValidationException::withMessages(['from' => 'Ein Serientermin hat keine Frist. Bitte zuerst einzeln ergänzen.']);
                }
                $original = Time::parse($row->$field)->tz('Europe/Zurich');
                $beforeTimes[$field] = $original->format('Y-m-d\TH:i');
                $time = $original->addMinutes((int) $data['shift_minutes']);
                if ($time->isPast()) {
                    throw ValidationException::withMessages(['shift_minutes' => 'Die Verschiebung würde einen Termin oder seine Frist in die Vergangenheit legen.']);
                }
                $next[$field] = $time->format('Y-m-d\TH:i');
            }
            if ($data['change_location'] ?? false) {
                $next['location'] = $data['location'] ?? null;
            }
            if ($data['change_title'] ?? false) {
                $next['title'] = $data['title'];
            }
            if ($data['change_capacity'] ?? false) {
                $next['capacity'] = $data['capacity'] ?? null;
                if ($next['capacity'] && $p->effectiveCount($tenant, $row) > $next['capacity']) {
                    throw ValidationException::withMessages(['capacity' => 'Die neue Kapazität ist kleiner als die Anzahl bestehender Anmeldungen.']);
                }
            }
            if ($data['cancel'] ?? false) {
                $next['status'] = 'ABGESAGT';
            }
            $changed = false;
            foreach ($next as $field => $value) {
                $before = $beforeTimes[$field] ?? $row->$field;
                $changed = $changed || (string) $before !== (string) $value;
            }
            if (! $changed) {
                $skipped++;

                continue;
            }
            $changes[] = ['id' => $row->id, 'before' => $row, 'input' => $next];
        }

        return ['changes' => $changes, 'skipped' => $skipped, 'fingerprint' => hash('sha256', json_encode($changes)), 'input' => $data];
    }

    public function apply(Account $actor, Tenant $tenant, int $id, array $input, string $fingerprint): int
    {
        $this->planning->authorizeOperation($actor, $tenant, 'update');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input, $fingerprint) {
            $this->planning->lock($tenant);
            $preview = $this->preview($actor, $tenant, $id, $input);
            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['revision' => 'Die Serie hat sich geändert. Bitte eine neue Vorschau erstellen.']);
            }
            foreach ($preview['changes'] as $change) {
                $this->planning->saveEvent($actor, $tenant, $change['id'], $change['input'], true);
            }

            return count($preview['changes']);
        });
    }
}
