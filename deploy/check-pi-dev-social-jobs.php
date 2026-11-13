<?php

require __DIR__.'/verify-pi-dev-vps-database.php';

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{SfvPlanningService, SocialJobService, SocialStudioData};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Http, Storage};
use Illuminate\Support\Str;

if (config('clubunio-social.test_enabled')) {
    exit(1);
}
Http::preventStrayRequests();
config(['clubunio-social.jobs_enabled' => true, 'logging.default' => 'stderr']);
$fake = new class(app(\App\ClubUnio\Services\TeamPlanningService::class)) extends SfvPlanningService {
    public function refreshObserved(Account $actor, Tenant $tenant, array $input, Closure $observeSchedule, ?string $seasonDate = null): int
    {
        return 1;
    }
    public function finalScores(Account $actor, Tenant $tenant, array $matches): array
    {
        return array_fill_keys(array_column($matches, 'match_id'), [3, 1]);
    }
};
$app->instance(SfvPlanningService::class, $fake);
$data = app(SocialStudioData::class);
$jobs = app(SocialJobService::class);
$db->beginTransaction();
try {
    $tenant = Tenant::create(['slug' => 'synthetic-social-job-'.Str::lower(Str::random(12)), 'name' => 'Synthetic Women']);
    $actor = new Account;
    $actor->forceFill(['username' => 'social-job-'.Str::random(16).'@example.invalid', 'display_name' => 'Synthetic editor',
        'password' => 'probe-not-a-password', 'active' => true, 'api_only' => false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $team = $db->table('clubora.teams')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic Team', 'sfv_team_id' => 991123]);
    foreach ([['2026-11-13', 991123, 991124], ['2026-11-12', 991124, 991123], ['2026-11-13', 991125, 991126]] as $i => [$date, $a, $b]) {
        $match = 998801 + $i;
        $db->table('clubora.sfv_schedule')->insert(['tenant_id' => $tenant->id, 'match_id' => $match, 'season_id' => 2027,
            'starts_at' => $date.'T16:00:00+01:00', 'team_a_id' => $a, 'team_b_id' => $b,
            'title' => 'Synthetic Match', 'host_name' => 'Home United', 'guest_name' => 'Visitors FC', 'location' => 'Arena', 'home' => true]);
        $db->table('clubora.competitions')->insert(['tenant_id' => $tenant->id, 'plays_on' => $date, 'starts_at' => '16:00',
            'host_name' => 'Home United', 'guest_name' => 'Visitors FC', 'association_match_id' => $match]);
    }
    foreach (['day' => ['fixtures'], 'result' => ['home', 'score', 'away']] as $kind => $fields) {
        $template = (string) Str::uuid();
        $design = ['format' => 'portrait', 'background' => '#234162', 'layers' => []];
        foreach ($fields as $i => $field) {
            $design['layers'][] = ['type' => 'field', 'field' => $field, 'x' => 100, 'y' => 100 + $i * 225,
                'w' => 880, 'h' => 220, 'angle' => 0, 'size' => 40];
        }
        $data->table($tenant->id, 'templates')->insert(['tenant_id' => $tenant->id, 'id' => $template, 'name' => $kind, 'revision' => 1]);
        $data->table($tenant->id, 'template_versions')->insert(['tenant_id' => $tenant->id, 'template_id' => $template, 'revision' => 1,
            'actor_id' => $actor->id, 'design' => $data->json($design)]);
        $jobs->save($actor, $tenant, ['name' => 'Synthetic '.$kind, 'kind' => $kind, 'local_time' => $kind === 'day' ? '08:00' : '02:00',
            'team_ids' => [$team], 'targets' => ['facebook'], 'template_id' => $template, 'template_revision' => 1,
            'locale' => 'en', 'enabled' => true, 'auto_publish' => true]);
    }
    $invoke = new ReflectionMethod(SocialJobService::class, 'runJob');
    foreach ($data->table($tenant->id, 'jobs')->get() as $job) {
        $now = CarbonImmutable::parse($job->kind === 'day' ? '2026-11-13T08:00:00+01:00' : '2026-11-13T02:00:00+01:00');
        if ($invoke->invoke($jobs, $job, $now) !== 1 || $invoke->invoke($jobs, $job, $now) !== 0) {
            throw new LogicException('Automation creation or duplicate protection failed');
        }
    }
    $posts = $data->table($tenant->id, 'posts')->get()->keyBy('kind');
    if ($posts->count() !== 2 || $posts['day']->state !== 'draft' || $posts['result']->state !== 'draft'
        || count($data->decode($posts['day']->snapshot)['fixtures']) !== 1
        || $data->decode($posts['result']->snapshot)['fixtures'][0]['score'] !== [3, 1]
        || ! str_contains($posts['result']->caption, '3:1') || Http::recorded()->isNotEmpty()) {
        throw new LogicException('Team filter, score, draft or test restriction failed');
    }
} finally {
    if (isset($tenant)) {
        Storage::disk('local')->delete($data->table($tenant->id, 'posts')->pluck('image_path')->all());
    }
    $db->rollBack();
}
if ($data->table($tenant->id, 'jobs')->exists()) {
    exit(1);
}
echo "DEV PostgreSQL: scheduled matchday and SFV result, team filter, no duplicate, draft-only and rollback verified.\n";
