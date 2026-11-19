<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{DB, Log, Storage, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

final class SocialJobService
{
    private array $refreshed = [];

    public function __construct(private SocialStudioData $data, private SocialJobRenderer $renderer, private SocialMetaTestPublisher $meta,
        private ModulePermissionService $permissions, private SfvPlanningService $sfv) {}

    public function index(Account $actor, Tenant $tenant): array
    {
        $this->permissions->authorize($actor, $tenant, 'social_media', 'schedule');
        $jobs = $this->data->table($tenant->id, 'jobs')->orderBy('name')->limit(50)->get()->map(function ($job) {
            $job->team_ids = $this->data->decode($job->team_ids);
            $job->targets = $this->data->decode($job->targets);
            $job->local_time = substr($job->local_time, 0, 5);

            return $job;
        });
        $teams = $this->domain($tenant, 'teams')->where('active', true)->orderBy('name')->get(['id', 'name', 'sfv_team_id']);
        return [
            'jobs' => $jobs, 'teams' => $teams,
            'templates' => $this->data->table($tenant->id, 'templates')->where('archived', false)->orderBy('name')->get(['id', 'name', 'revision']),
            'runs' => $this->data->table($tenant->id, 'job_runs')->where('slot', 'not like', 'scan:%')->orderByDesc('updated_at')->limit(40)->get(),
            'languages' => array_keys(app(LanguagePreferences::class)->enabled()),
            'auto_allowed' => $this->permissions->allows($actor, $tenant, 'social_media', 'send')
                && $this->permissions->allows($actor, $tenant, 'social_media', 'approve'),
            'runner_enabled' => $this->ready(),
            // The job's composite foreign key guarantees its pinned revision exists.
            'saved_versions' => $jobs->mapWithKeys(fn ($job) => [$job->id => true]),
        ];
    }

    public function save(Account $actor, Tenant $tenant, array $input): array
    {
        $this->permissions->authorize($actor, $tenant, 'social_media', 'schedule');
        $this->permissions->authorize($actor, $tenant, 'social_media', 'create');
        $data = Validator::make($input, [
            'id' => 'nullable|uuid', 'revision' => 'required_with:id|nullable|integer|min:1',
            'name' => 'required|string|max:120', 'kind' => 'required|in:day,result', 'local_time' => 'required|date_format:H:i',
            'team_ids' => 'required|array|min:1|max:50', 'team_ids.*' => 'required|integer|min:1|distinct',
            'targets' => 'required|array|min:1|max:2', 'targets.*' => 'required|distinct|in:instagram,facebook',
            'template_id' => 'required|uuid', 'template_revision' => 'required|integer|min:1',
            'locale' => ['required', Rule::in(array_keys(app(LanguagePreferences::class)->enabled()))],
            'caption' => 'nullable|string|max:2000', 'enabled' => 'required|boolean', 'auto_publish' => 'required|boolean',
        ])->validate();
        if ($data['auto_publish']) {
            $this->permissions->authorize($actor, $tenant, 'social_media', 'approve');
            $this->permissions->authorize($actor, $tenant, 'social_media', 'send');
        }
        if ($data['kind'] === 'result') {
            if ($data['enabled']) {
                $this->permissions->authorize($actor, $tenant, 'competitions', 'sync');
            }
            $this->data->require($data['local_time'] < '06:00', 'job_night_required');
        }
        $teams = $this->domain($tenant, 'teams')->whereIn('id', $data['team_ids'])->where('active', true)->get(['id', 'sfv_team_id']);
        $this->data->require($teams->count() === count($data['team_ids']) && ($data['kind'] !== 'result'
            || $teams->every(fn ($team) => (int) $team->sfv_team_id > 0)), 'job_team_invalid');

        return DB::connection('clubunio')->transaction(function () use ($tenant, $actor, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $jobs = $this->data->table($tenant->id, 'jobs');
            $id = $data['id'] ?? (string) Str::uuid();
            $existing = isset($data['id']) ? (clone $jobs)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if(isset($data['id']) && (! $existing || (int) $existing->revision !== (int) $data['revision']), 409);
            $template = $this->data->table($tenant->id, 'templates')->where('id', $data['template_id'])->first();
            $pinned = $existing && $existing->template_id === $data['template_id']
                && (int) $existing->template_revision === (int) $data['template_revision'];
            $this->data->require($template && ((! $template->archived && (int) $template->revision === (int) $data['template_revision'])
                || ($pinned && (! $template->archived || ! $data['enabled']))), 'job_template_invalid');
            $design = $this->design($tenant, (object) $data);
            $fields = array_column($design['layers'], 'field');
            $this->data->require($data['kind'] === 'day' ? in_array('fixtures', $fields, true)
                : count(array_intersect(['home', 'away', 'score'], $fields)) === 3, 'job_template_fields');
            abort_if(! $existing && (clone $jobs)->count() >= 50, 422);
            unset($data['id']);
            $values = array_replace($data, ['team_ids' => $this->data->json(array_map('intval', $data['team_ids'])),
                'targets' => $this->data->json($data['targets']), 'caption' => $data['caption'] ?? '',
                'actor_id' => $actor->id, 'updated_at' => now(), 'revision' => ($existing?->revision ?? 0) + 1]);
            $jobs->updateOrInsert(['id' => $id, 'tenant_id' => $tenant->id], $values);

            return ['id' => $id];
        });
    }

    public function ready(): bool
    {
        $db = DB::connection('clubunio');

        return config('clubunio-social.jobs_enabled') && rtrim((string) config('app.url'), '/') === config('clubunio-social.dev_origin')
            && $db->getSchemaBuilder()->hasTable('clubunio.social_jobs');
    }

    public function preview(Account $actor, Tenant $tenant, array $input)
    {
        $this->permissions->authorize($actor, $tenant, 'social_media', 'schedule');
        $data = Validator::make($input, ['template_id' => 'required|uuid', 'template_revision' => 'required|integer|min:1'])->validate();
        $day = now('Europe/Zurich')->toDateString();
        $path = $this->renderer->render($tenant->id, $this->design($tenant, (object) $data), [[
            'date' => $day, 'time' => '19:00', 'home' => trans('social.sample_home'), 'away' => trans('social.sample_away'),
            'place' => 'Arena 1', 'score' => [3, 1],
        ]], $tenant->name, $day);
        try {
            $bytes = Storage::disk('local')->get($path);
        } finally {
            Storage::disk('local')->delete($path);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function due(Time $now, string $localTime, string $kind): bool
    {
        $local = $now->tz('Europe/Zurich');
        [$h, $m] = array_map('intval', explode(':', $localTime));
        $minute = $local->hour * 60 + $local->minute;
        $start = $h * 60 + $m;

        return $minute >= $start && $minute < ($kind === 'result' ? 360 : $start + 20)
            && ($kind !== 'result' || ($minute - $start) % 15 < 3);
    }

    public function tick(?Time $now = null): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $now = ($now ?? Time::now())->tz('Europe/Zurich');
        $this->refreshed = [];
        $done = 0;
        $jobs = DB::connection('clubunio')->table('clubunio.social_jobs')->where('enabled', true)->orderBy('id')->get();
        foreach ($jobs as $job) {
            if (! self::due($now, $job->local_time, $job->kind)) {
                continue;
            }
            try {
                $done += $this->runJob($job, $now);
            } catch (Throwable $e) {
                Log::warning('clubunio.social.job_failed', ['tenant_id' => $job->tenant_id, 'job_id' => $job->id, 'exception' => $e::class]);
                $this->log($job, 'cycle:'.$now->toDateString(), $now->toDateString(), 'job_failed');
            }
        }

        return $done;
    }

    public function runDraftReplay(Tenant $tenant, string $id, Time $now): int
    {
        abort_unless(app()->runningInConsole() && $this->ready() && ! config('clubunio-social.test_enabled'), 403);
        $job = $this->data->table($tenant->id, 'jobs')->where('id', $id)->where('enabled', true)->where('auto_publish', false)->first();
        abort_unless($job && self::due($now, $job->local_time, $job->kind), 422);
        $this->refreshed = [];

        return $this->runJob($job, $now->tz('Europe/Zurich'));
    }

    private function runJob(object $job, Time $now): int
    {
        $tenant = Tenant::findOrFail($job->tenant_id);
        $actor = Account::findOrFail($job->actor_id);
        $this->permissions->authorize($actor, $tenant, 'social_media', 'schedule');
        $this->permissions->authorize($actor, $tenant, 'social_media', 'create');
        if ($job->auto_publish) {
            $this->permissions->authorize($actor, $tenant, 'social_media', 'approve');
            $this->permissions->authorize($actor, $tenant, 'social_media', 'send');
        }
        $day = $now->toDateString();
        $runs = $this->data->table($tenant->id, 'job_runs')->where('job_id', $job->id);
        if ($job->kind === 'day' && (clone $runs)->where('slot', 'day:'.$day)->exists()) {
            return 0;
        }
        $template = $this->data->table($tenant->id, 'templates')->where('id', $job->template_id)->first();
        if (! $template || $template->archived) {
            $this->log($job, 'cycle:'.$day, $day, 'job_template_invalid');

            return 0;
        }
        $ids = $this->data->decode($job->team_ids);
        $teams = $this->domain($tenant, 'teams')->where('active', true)->whereIn('id', $ids)->get(['id', 'sfv_team_id']);
        if ($teams->count() !== count($ids) || ! isset(app(LanguagePreferences::class)->enabled()[$job->locale])
            || ($job->kind === 'result' && $teams->contains(fn ($team) => (int) $team->sfv_team_id < 1))) {
            $this->log($job, 'cycle:'.$day, $day, 'job_team_invalid');

            return 0;
        }
        if ($job->kind === 'day') {
            $fixtures = $this->dayFixtures($tenant, $day, $ids, $teams->pluck('sfv_team_id')->filter()->all());
            if (! $fixtures) {
                $this->log($job, 'day:'.$day, $day, 'job_empty');

                return 0;
            }

            return (int) $this->createPost($job, $tenant, $actor, 'day:'.$day, $day, $day, $fixtures);
        }

        // Refresh a bounded window once per tenant per tick, then ask for details only
        // for selected, not-yet-posted fixtures. Nightly rechecks catch delayed results.
        $from = $now->subDays(3)->toDateString();
        $until = $now->subDay()->toDateString();
        $interval = (string) intdiv($now->timestamp, 900);
        $claimed = $this->data->table($tenant->id, 'job_runs')->insertOrIgnore(['tenant_id' => $tenant->id, 'job_id' => $job->id,
            'slot' => 'scan:'.$interval, 'run_date' => $day, 'state' => 'job_checked']);
        if (! $claimed) {
            return 0;
        }
        if (! isset($this->refreshed[$tenant->id])) {
            $observations = [];
            $observe = static function (array $rows) use (&$observations): void {
                foreach ($rows as $row) {
                    $id = (int) $row['matchId'];
                    $observations[$id] = array_key_exists($id, $observations) ? [] : $row;
                }
            };
            // At a season boundary, retain the previous season's last matchdays.
            $boundary = $now->month === 7 && $now->day <= 3 ? $now->startOfMonth()->toDateString() : null;
            if ($boundary && $from < $boundary) {
                $this->sfv->refreshObserved($actor, $tenant, ['from' => $from, 'to' => min($until, $now->startOfMonth()->subDay()->toDateString())], $observe, $from);
            }
            if (! $boundary || $until >= $boundary) {
                $this->sfv->refreshObserved($actor, $tenant, ['from' => $boundary ? max($from, $boundary) : $from, 'to' => $until], $observe);
            }
            $this->refreshed[$tenant->id] = $observations;
        }
        $sfvIds = $teams->pluck('sfv_team_id')->filter()->all();
        $candidates = $this->domain($tenant, 'sfv_schedule')->where('starts_at', '>=', Time::parse($from, 'Europe/Zurich')->toIso8601String())
            ->where('starts_at', '<', $now->startOfDay()->toIso8601String())
            ->where(fn ($q) => $q->whereIn('team_a_id', $sfvIds)->orWhereIn('team_b_id', $sfvIds))
            ->orderByDesc('starts_at')->limit(100)->get();
        $processed = (clone $runs)->whereIn('slot', $candidates->map(fn ($m) => 'sfv:'.$m->match_id))->pluck('slot')->flip();
        $pending = $candidates->reject(fn ($m) => $processed->has('sfv:'.$m->match_id))->values();
        $offset = ((int) $interval % max(1, (int) ceil($pending->count() / 12))) * 12;
        $matches = $pending->slice($offset, 12)->all();
        $scores = [];
        $detailCandidates = [];
        foreach ($matches as $match) {
            $row = $this->refreshed[$tenant->id][$match->match_id] ?? null;
            if ($row === []) {
                continue;
            }
            if ($row && ($score = SfvPlanningService::scheduleScore($match, $row)) !== null) {
                $scores[$match->match_id] = $score;
            } else {
                $detailCandidates[] = $match;
            }
        }
        $scores += $this->sfv->finalScores($actor, $tenant, $detailCandidates);
        $count = 0;
        foreach ($matches as $match) {
            if (! isset($scores[$match->match_id])) {
                continue;
            }
            $date = Time::parse($match->starts_at)->tz('Europe/Zurich');
            $fixture = ['id' => (int) $match->match_id, 'date' => $date->toDateString(), 'time' => $date->format('H:i'),
                'home' => $match->host_name, 'away' => $match->guest_name, 'place' => $match->location, 'score' => $scores[$match->match_id]];
            $count += (int) $this->createPost($job, $tenant, $actor, 'sfv:'.$match->match_id, $day, $date->toDateString(), [$fixture]);
        }
        $this->log($job, 'cycle:'.$day, $day, $count ? 'job_checked' : 'job_waiting');

        return $count;
    }

    private function dayFixtures(Tenant $tenant, string $date, array $ids, array $sfvIds): array
    {
        // Qualify the alias because both sources carry tenant_id.
        $local = DB::connection('clubunio')->table('clubunio.pitch_competition_teams as links')->where('links.tenant_id', $tenant->id)
            ->join('clubunio.team_seasons as ts', fn ($join) => $join->on('ts.id', '=', 'links.team_season_id')->on('ts.tenant_id', '=', 'links.tenant_id'))
            ->where('ts.active', true)->whereIn('ts.team_id', $ids)->select('links.competition_id');
        $source = $this->domain($tenant, 'sfv_schedule')->where(fn ($q) => $q->whereIn('team_a_id', $sfvIds)->orWhereIn('team_b_id', $sfvIds))->select('match_id');
        $ids = $this->domain($tenant, 'competitions')->where('active', true)->where('plays_on', $date)
            ->where(fn ($q) => $q->whereIn('id', $local)->orWhere(fn ($fallback) => $fallback
                ->whereNotIn('id', $this->domain($tenant, 'pitch_competition_settings')->select('competition_id'))
                ->whereIn('association_match_id', $source)))->pluck('id')->all();
        $rows = array_values(array_filter($this->data->fixtures($tenant, $date), fn ($row) => in_array($row['id'], $ids) && $row['time'] !== ''));
        if (count($rows) > 12 || count($rows) !== count($ids)) {
            throw new \RuntimeException('Too many or incomplete matchday fixtures');
        }

        return $rows;
    }

    private function createPost(object $job, Tenant $tenant, Account $actor, string $slot, string $day, string $date, array $fixtures): bool
    {
        $id = (string) Str::uuid();
        $design = $this->design($tenant, $job);
        $path = $this->renderer->render($tenant->id, $design, $fixtures, $tenant->name, $date);
        $targets = $this->data->decode($job->targets);
        $snapshot = ['date' => $date, 'fixtures' => $fixtures];
        if ($job->kind === 'result') {
            $snapshot['source'] = 'sfv_final';
        } else {
            $snapshot['fixtures'] = array_map(fn ($row) => array_diff_key($row, ['score' => true]), $fixtures);
        }
        $approved = $job->auto_publish && collect($targets)->every(fn ($channel) => $this->meta->target($tenant->id, $channel, config('app.url')));
        $first = $fixtures[0];
        $caption = $job->caption !== '' ? $job->caption : trans('social.'.$job->kind.'_heading', [], $job->locale)."\n"
            .($job->kind === 'result' ? $first['home'].' '.implode(':', $first['score']).' '.$first['away']
                : implode("\n", array_map(fn ($row) => trim($row['time'].' '.$row['home'].' – '.$row['away'].' '.$row['place']), $fixtures)));
        $caption = mb_substr($caption, 0, 2200);
        $saved = false;
        try {
            $saved = DB::connection('clubunio')->transaction(function () use ($job, $tenant, $actor, $id, $path, $slot, $day, $design, $snapshot, $targets, $approved, $caption) {
                $current = $this->data->table($tenant->id, 'jobs')->where('id', $job->id)->lockForUpdate()->first();
                if (! $current?->enabled || $current->revision != $job->revision || $this->data->table($tenant->id, 'job_runs')
                    ->where('job_id', $job->id)->where('slot', $slot)->exists()) {
                    return false;
                }
                $this->data->table($tenant->id, 'posts')->insert([
                    'id' => $id, 'tenant_id' => $tenant->id, 'kind' => $job->kind, 'title' => $job->name,
                    'caption' => $caption, 'design' => $this->data->json($design), 'snapshot' => $this->data->json($snapshot),
                    'targets' => $this->data->json($targets), 'image_path' => $path, 'state' => $approved ? 'approved' : 'draft',
                    'actor_id' => $actor->id, 'approved_by' => $approved ? $actor->id : null, 'approved_at' => $approved ? now() : null,
                ]);
                $this->data->table($tenant->id, 'job_runs')->insert(['tenant_id' => $tenant->id, 'job_id' => $job->id,
                    'slot' => $slot, 'run_date' => $day, 'state' => $approved ? 'job_prepared' : ($job->auto_publish ? 'job_test_missing' : 'draft'), 'post_id' => $id]);

                return true;
            });
        } finally {
            if (! $saved) {
                Storage::disk('local')->delete($path);
            }
        }
        if ($saved && $approved) {
            $states = [];
            foreach ($targets as $channel) {
                try {
                    $states[] = $this->meta->publish($tenant, $actor, $id, 1, $channel, config('app.url'), function () use ($tenant, $job, $snapshot) {
                        $current = $this->data->table($tenant->id, 'jobs')->where('id', $job->id)->lockForUpdate()->first();
                        abort_unless($current?->enabled && $current->auto_publish && $current->revision == $job->revision, 409);
                        $this->permissions->authorize(Account::findOrFail($job->actor_id), $tenant, 'social_media', 'schedule');
                        $this->data->assertSnapshot($tenant, $job->kind, $snapshot);
                    });
                } catch (Throwable $error) {
                    $states[] = 'review';
                    Log::warning('clubunio.social.job_channel_review', ['job_id' => $job->id, 'channel' => $channel, 'exception' => $error::class]);
                }
            }
            $this->log($job, $slot, $day, count(array_filter($states, fn ($state) => $state === 'published')) === count($states) ? 'published' : 'job_review');
        }

        return $saved;
    }

    private function log(object $job, string $slot, string $date, string $state): void
    {
        $this->data->table($job->tenant_id, 'job_runs')->updateOrInsert(['tenant_id' => $job->tenant_id, 'job_id' => $job->id, 'slot' => $slot],
            ['run_date' => $date, 'state' => $state, 'updated_at' => now()]);
    }

    private function design(Tenant $tenant, object $job): array
    {
        $value = $this->data->table($tenant->id, 'template_versions')->where('template_id', $job->template_id)
            ->where('revision', $job->template_revision)->value('design');
        abort_unless($value, 409);

        return $this->data->decode($value);
    }

    private function domain(Tenant $tenant, string $table)
    {
        return DB::connection('clubunio')->table('clubunio.'.$table)->where('tenant_id', $tenant->id);
    }
}
