<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SfvPlanningService
{
    public function __construct(private TeamPlanningService $planning) {}

    private function authorizeSync(Account $actor, Tenant $tenant): void
    {
        if (app(ModulePermissionService::class)->allows($actor, $tenant, 'competitions', 'sync')) {
            abort_unless($this->planning->table($tenant, 'team_seasons')->where('active', true)->exists(), 403);

            return;
        }
        $this->planning->authorizeOperation($actor, $tenant, 'sync');
        abort_unless($this->planning->teams($actor, $tenant, true, 'sync')->exists(), 403);
    }

    private function setting(Tenant $tenant, string $key, string $default = ''): string
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('key', $key)->where('enabled', true)->first();

        return $row ? trim(Crypt::decryptString($row->encrypted_value)) : $default;
    }

    public function refresh(Account $actor, Tenant $tenant, array $input): int
    {
        $this->authorizeSync($actor, $tenant);
        $data = Validator::make($input, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();

        return app(SyncWorkService::class)->run($actor, $tenant, 'sfv_schedule', $tenant->id, $data);
    }

    public function refreshNow(Account $actor, Tenant $tenant, array $input, ?Closure $authorization = null): int
    {
        return $this->refreshSchedule($actor, $tenant, $input, $authorization);
    }

    public function refreshObserved(Account $actor, Tenant $tenant, array $input, Closure $observeSchedule, ?string $seasonDate = null): int
    {
        return $this->refreshSchedule($actor, $tenant, $input, seasonDate: $seasonDate, observeSchedule: $observeSchedule);
    }

    private function refreshSchedule(Account $actor, Tenant $tenant, array $input, ?Closure $authorization = null, ?string $seasonDate = null, ?Closure $observeSchedule = null): int
    {
        $this->authorizeSync($actor, $tenant);
        $data = Validator::make($input, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        if (Time::parse($data['from'])->diffInDays(Time::parse($data['to'])) > 186) {
            throw ValidationException::withMessages(['to' => __('Maximal 186 Tage pro Abruf.')]);
        }
        try {
            $base = rtrim($this->setting($tenant, 'SFV_API_BASE_URL', 'https://stg-club-api-services.football.ch'), '/');
            if (! in_array($base, ['https://stg-club-api-services.football.ch', 'https://club-api-services.football.ch'], true)) {
                throw new \RuntimeException('Invalid provider configuration');
            }
            $key = $this->setting($tenant, 'SFV_APPLICATION_KEY');
            $pass = $this->setting($tenant, 'SFV_APPLICATION_PASS');
            $club = (int) $this->setting($tenant, 'SFV_CLUB_ID');
            if (! $key || ! $pass || ! $club) {
                throw new \RuntimeException('Missing configuration');
            }
            $client = fn () => Http::acceptJson()->connectTimeout(5)->timeout(35)->withoutRedirecting();
            $auth = $client()->post($base.'/api/token', ['applicationKey' => $key, 'applicationPass' => $pass]);
            if (! $auth->successful()) {
                throw new \RuntimeException('Authentication failed', $auth->status());
            }
            $body = $auth->json();
            $token = is_string($body) ? $body : ($body['token'] ?? $body['userToken'] ?? $body['access_token'] ?? null);
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Invalid authentication response');
            }
            $now = $seasonDate ? Time::parse($seasonDate, 'Europe/Zurich') : now('Europe/Zurich');
            $season = $this->setting($tenant, 'SFV_SEASON_ID_AUTO', '1');
            $season = in_array(strtolower($season), ['0', 'false', 'nein', 'no'], true) ? (int) $this->setting($tenant, 'SFV_SEASON_ID') : $now->year + (int) ($now->month >= 7);
            $language = $observeSchedule ? '1' : $this->setting($tenant, 'SFV_LANGUAGE', '1');
            $response = $client()->withHeaders(['X-User-Token' => $token, 'X-User-Language' => $language])
                ->get($base.'/api/club/schedule', ['SeasonId' => $season, 'ClubId' => $club, 'Language' => $language, 'DateFrom' => $data['from'].'T00:00:00', 'DateUntil' => $data['to'].'T23:59:59']);
            if (! $response->successful()) {
                throw new \RuntimeException('Schedule failed', $response->status());
            }
            $matches = $response->json();
            if (! is_array($matches) || ! array_is_list($matches) || count($matches) > 5000) {
                throw new \RuntimeException('Invalid schedule');
            }
            $own = $this->planning->table($tenant, 'teams')->whereNotNull('sfv_team_id')->pluck('sfv_team_id')->map(fn ($id) => (int) $id)->all();
            $rows = [];
            foreach ($matches as $match) {
                if (! is_array($match) || ! is_numeric($match['matchId'] ?? null) || (int) $match['matchId'] < 1) {
                    throw new \RuntimeException('Invalid match');
                }
                $date = empty($match['matchDate']) ? null : Time::parse($match['matchDate'], 'Europe/Zurich')->toIso8601String();
                $location = mb_substr((string) ($match['stadiumPlaygroundName'] ?? ''), 0, 255);
                $a = (int) ($match['teamAId'] ?? 0);
                $b = (int) ($match['teamBId'] ?? 0);
                $rows[] = ['match_id' => (int) $match['matchId'], 'season_id' => (int) ($match['seasonId'] ?? $season),
                    'host_name' => mb_substr(trim((string) ($match['teamNameA'] ?? '')), 0, 255),
                    'guest_name' => mb_substr(trim((string) ($match['teamNameB'] ?? '')), 0, 255),
                    'team_a_id' => $a ?: null, 'team_b_id' => $b ?: null,
                    'title' => mb_substr(($match['teamNameA'] ?? '?').' - '.($match['teamNameB'] ?? '?'), 0, 255),
                    'starts_at' => $date, 'location' => $location,
                    'state' => mb_substr((string) ($match['matchStateName'] ?? ''), 0, 100),
                    'match_type' => mb_substr((string) ($match['matchTypeName'] ?? ''), 0, 100),
                    'home' => in_array($a, $own, true) || (! in_array($b, $own, true) && stripos($location, 'Sissach') !== false)];
            }

            $count = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $rows, $authorization) {
                $p = $this->planning;
                $p->lock($tenant);
                $authorization?->__invoke();
                $this->authorizeSync($actor, $tenant);
                foreach ($rows as $row) {
                    $q = $p->table($tenant, 'sfv_schedule')->where('match_id', $row['match_id']);
                    $old = (clone $q)->first();
                    $row['revision'] = ($old->revision ?? 0) + 1;
                    $row['updated_at'] = now()->toIso8601String();
                    if ($old) {
                        $q->update($row);
                    } else {
                        $q->insert($row + ['tenant_id' => $tenant->id]);
                    }
                }
                $p->audit($actor, $tenant, 'sfv_schedule_refresh', $tenant->id, null, count($rows));

                return count($rows);
            });
            $observeSchedule?->__invoke($matches);

            return $count;
        } catch (\Throwable $error) {
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            $reference = (string) Str::uuid();
            Log::warning('clubunio.sfv.refresh_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'provider_status' => (int) $error->getCode()]);
            throw ValidationException::withMessages(['sfv' => __('SFV-Abruf fehlgeschlagen. Bestehende Termine bleiben unverändert. Referenz: :value1', ['value1' => $reference])]);
        }
    }

    public static function scheduleScore(object $match, array $row): ?array
    {
        // Require both observed SFV markers; the Swagger schema does not define the numeric enum.
        if (($row['matchState'] ?? null) !== 2 || mb_strtolower(trim((string) ($row['matchStateName'] ?? ''))) !== 'ausgetragen'
            || ($row['teamAId'] ?? null) !== (int) $match->team_a_id || ($row['teamBId'] ?? null) !== (int) $match->team_b_id) {
            return null;
        }
        return self::finalScore($match, [...$row, 'hasMatchEnded' => true]);
    }

    /**
     * Read the SFV detail endpoint without collecting players, events, or staff data.
     * The result is usable only when SFV explicitly confirms that play ended.
     */
    public function finalScores(Account $actor, Tenant $tenant, array $matches): array
    {
        $this->authorizeSync($actor, $tenant);
        if ($matches === []) {
            return [];
        }
        $base = rtrim($this->setting($tenant, 'SFV_API_BASE_URL', 'https://stg-club-api-services.football.ch'), '/');
        $key = $this->setting($tenant, 'SFV_APPLICATION_KEY');
        $pass = $this->setting($tenant, 'SFV_APPLICATION_PASS');
        if (! in_array($base, ['https://stg-club-api-services.football.ch', 'https://club-api-services.football.ch'], true) || ! $key || ! $pass) {
            throw new \RuntimeException('SFV configuration unavailable');
        }
        $client = fn () => Http::acceptJson()->connectTimeout(4)->timeout(8)->withoutRedirecting();
        $body = $client()->post($base.'/api/token', ['applicationKey' => $key, 'applicationPass' => $pass])->throw()->json();
        $token = is_string($body) ? $body : ($body['token'] ?? $body['userToken'] ?? $body['access_token'] ?? null);
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Invalid SFV authentication response');
        }
        $scores = [];
        foreach ($matches as $match) {
            $response = $client()->withHeaders(['X-User-Token' => $token, 'X-User-Language' => $this->setting($tenant, 'SFV_LANGUAGE', '1')])
                ->get($base.'/api/match/'.(int) $match->match_id);
            if (in_array($response->status(), [403, 404, 406], true)) {
                continue;
            }
            $detail = $response->throw()->json();
            if ($score = self::finalScore($match, $detail)) {
                $scores[(int) $match->match_id] = $score;
            }
        }

        return $scores;
    }

    public static function finalScore(object $match, mixed $detail): ?array
    {
        if (! is_array($detail) || ($detail['hasMatchEnded'] ?? false) !== true
            || ($detail['matchId'] ?? null) !== (int) $match->match_id
            || ! is_string($detail['matchDate'] ?? null) || ! $match->starts_at) {
            return null;
        }
        try {
            if (! Time::parse($detail['matchDate'], 'Europe/Zurich')->equalTo(Time::parse($match->starts_at, 'Europe/Zurich'))) {
                return null;
            }
        } catch (\Exception) {
            return null;
        }
        foreach (['scoreTeamA', 'scoreTeamB'] as $field) {
            if (! is_int($detail[$field] ?? null) || $detail[$field] < 0 || $detail[$field] > 999) {
                return null;
            }
        }
        return [$detail['scoreTeamA'], $detail['scoreTeamB']];
    }

    public function import(Account $actor, Tenant $tenant, int $id, array $input): int
    {
        $this->planning->authorizeOperation($actor, $tenant, 'sync');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            $p = $this->planning;
            $p->lock($tenant);
            $p->authorizeOperation($actor, $tenant, 'sync');
            $p->enter($actor, $tenant);
            $match = $p->table($tenant, 'sfv_schedule')->where('id', $id)->first();
            abort_unless($match, 404);
            $p->conflict($match, ['revision' => $input['sfv_revision'] ?? 0]);
            $team = $p->teams($actor, $tenant, true)->where('id', (int) ($input['team_season_id'] ?? 0))->first();
            abort_unless($team, 403);
            $sfvTeam = (int) $p->table($tenant, 'teams')->where('id', $team->team_id)->value('sfv_team_id');
            if (! $sfvTeam || ! in_array($sfvTeam, [(int) $match->team_a_id, (int) $match->team_b_id], true)) {
                throw ValidationException::withMessages(['team_season_id' => __('Die SFV-Team-ID passt nicht zu diesem Spiel.')]);
            }
            $old = $p->table($tenant, 'team_events', true)->where('team_season_id', $team->id)->where('sfv_match_id', $match->match_id)->first();
            abort_if(($old->deleted_at ?? null) !== null, 409, __('Dieser SFV-Termin wurde entfernt und wird nicht erneut angelegt.'));
            if ($old) {
                $p->conflict($old, ['revision' => $input['event_revision'] ?? 0]);
            } elseif (! empty($input['event_revision'])) {
                abort(409);
            }
            $eventInput = $input;
            $eventInput['revision'] = $old->revision ?? 0;
            // Existing settings remain editable in the confirmation form; no unattended overwrite.
            $eventId = $p->saveEvent($actor, $tenant, $old?->id, $eventInput);
            $p->table($tenant, 'team_events')->where('id', $eventId)->update(['sfv_match_id' => $match->match_id]);

            return $eventId;
        });
    }
}
