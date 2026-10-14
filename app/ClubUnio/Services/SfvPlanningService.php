<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SfvPlanningService
{
    public function __construct(private TeamPlanningService $planning) {}

    private function setting(Tenant $tenant, string $key, string $default = ''): string
    {
        $row = Setting::where('tenant_id', $tenant->id)->where('key', $key)->where('enabled', true)->first();

        return $row ? trim(Crypt::decryptString($row->encrypted_value)) : $default;
    }

    public function refresh(Account $actor, Tenant $tenant, array $input): int
    {
        $this->planning->enter($actor, $tenant);
        abort_unless($this->planning->teams($actor, $tenant, true)->exists(), 403);
        $data = Validator::make($input, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        if (Time::parse($data['from'])->diffInDays(Time::parse($data['to'])) > 186) {
            throw ValidationException::withMessages(['to' => 'Maximal 186 Tage pro Abruf.']);
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
            $now = now('Europe/Zurich');
            $season = $this->setting($tenant, 'SFV_SEASON_ID_AUTO', '1');
            $season = in_array(strtolower($season), ['0', 'false', 'nein', 'no'], true) ? (int) $this->setting($tenant, 'SFV_SEASON_ID') : $now->year + (int) ($now->month >= 7);
            $response = $client()->withHeaders(['X-User-Token' => $token, 'X-User-Language' => $this->setting($tenant, 'SFV_LANGUAGE', '1')])
                ->get($base.'/api/club/schedule', ['SeasonId' => $season, 'ClubId' => $club, 'Language' => $this->setting($tenant, 'SFV_LANGUAGE', '1'), 'DateFrom' => $data['from'].'T00:00:00', 'DateUntil' => $data['to'].'T23:59:59']);
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

            return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $rows) {
                $p = $this->planning;
                $p->lock($tenant);
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
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            Log::warning('clubunio.sfv.refresh_failed', ['reference' => $reference, 'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'provider_status' => (int) $error->getCode()]);
            throw ValidationException::withMessages(['sfv' => 'SFV-Abruf fehlgeschlagen. Bestehende Termine bleiben unverändert. Referenz: '.$reference]);
        }
    }

    public function import(Account $actor, Tenant $tenant, int $id, array $input): int
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            $p = $this->planning;
            $p->lock($tenant);
            $p->enter($actor, $tenant);
            $match = $p->table($tenant, 'sfv_schedule')->where('id', $id)->first();
            abort_unless($match, 404);
            $p->conflict($match, ['revision' => $input['sfv_revision'] ?? 0]);
            $team = $p->teams($actor, $tenant, true)->where('id', (int) ($input['team_season_id'] ?? 0))->first();
            abort_unless($team, 403);
            $sfvTeam = (int) $p->table($tenant, 'teams')->where('id', $team->team_id)->value('sfv_team_id');
            if (! $sfvTeam || ! in_array($sfvTeam, [(int) $match->team_a_id, (int) $match->team_b_id], true)) {
                throw ValidationException::withMessages(['team_season_id' => 'Die SFV-Team-ID passt nicht zu diesem Spiel.']);
            }
            $old = $p->table($tenant, 'team_events')->where('team_season_id', $team->id)->where('sfv_match_id', $match->match_id)->first();
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
