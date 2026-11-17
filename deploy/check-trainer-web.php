<?php

use App\ClubUnio\Mobile\MobileSeasonScope;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\TeamPlanningService;
use App\ClubUnio\Services\TrainerWebSeasonScope;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || ! str_starts_with(getcwd(), '/var/www/svs-laravel/releases/')) {
    exit(1);
}

try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = DB::connection('clubunio');
    $origin = 'https://dev.clubunio.ch';
    if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'clubunio_dev'
        || $db->getConfig('search_path') !== 'clubunio' || config('app.url') !== $origin) {
        throw new LogicException('ClubUnio DEV required');
    }
    config(['session.driver' => 'array', 'cache.default' => 'array']);
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tenant = Tenant::where('slug', 'sv-sissach')->sole();
        $season = app(MobileSeasonScope::class)->current($tenant) ?? throw new LogicException('No current season');
        $access = app(MemberAccessService::class);
        $planning = app(TeamPlanningService::class);
        $security = app(AccountSecurity::class);
        $candidate = null;
        foreach (Account::where('active', true)->where('api_only', false)->whereIn('id',
            $planning->table($tenant, 'tenant_accounts')->whereIn('role', ['trainer', 'member'])->where('portal_enabled', true)->select('account_id'))->orderBy('id')->cursor() as $actor) {
            if ($access->membership($actor, $tenant)?->role !== 'trainer' || ! $access->canEnter($actor, $tenant) || $security->requiresStrongLogin($actor)) {
                continue;
            }
            $teamRows = $planning->table($tenant, 'team_seasons')->whereIn('id', $access->responsibleTeams($actor, $tenant))->get(['id', 'season_id']);
            $current = $teamRows->where('season_id', $season->id)->pluck('id');
            if ($current->isEmpty()) {
                continue;
            }
            $old = $teamRows->where('season_id', '!=', $season->id)->pluck('id');
            $historicalEvent = $planning->table($tenant, 'team_events')->whereIn('team_season_id', $old)->value('id');
            $candidate ??= [$actor, $current, $old, $historicalEvent];
            if ($historicalEvent !== null) {
                $candidate = [$actor, $current, $old, $historicalEvent];
                break;
            }
        }
        [$actor, $current, $old, $historicalEvent] = $candidate ?? throw new LogicException('No eligible real DEV trainer');
        // Authenticate only inside this CLI kernel, without creating or exporting a browser session.
        auth('portal')->setUser($actor);
        $kernel = app(Kernel::class);
        $fetch = function (string $path, int $expected) use ($kernel, $origin) {
            $request = Request::create($origin.$path);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            if ($response->getStatusCode() !== $expected) {
                throw new LogicException('Unexpected HTTP '.$response->getStatusCode().' for '.$request->route()?->getName());
            }

            return $response;
        };
        foreach ([
            ['/portal/'.$tenant->slug.'/members', 'de', 'Portal-Navigation'],
            ['/planning/'.$tenant->slug.'/calendar', 'en', 'Portal navigation'],
            ['/planning/'.$tenant->slug.'/absences', 'de', 'Portal-Navigation'],
            ['/bus/'.$tenant->slug, 'de', 'Portal-Navigation'],
        ] as [$path, $locale, $label]) {
            $actor->setAttribute('preferred_locale', $locale);
            $response = $fetch($path, 200);
            $html = $response->getContent();
            if (! str_contains($html, 'id="cu-portal-sidebar"') || ! str_contains($html, 'aria-label="'.$label.'"')
                || ! str_contains($html, 'clubunio-portal-shell.css') || $response->headers->get('Content-Language') !== $locale
                || str_contains($html, '/portal/'.$tenant->slug.'/registrations"') || str_contains($html, '/bus/'.$tenant->slug.'/options"')) {
                throw new LogicException('Trainer navigation did not render as expected');
            }
            if (! app(TrainerWebSeasonScope::class)->applies($actor, $tenant)) {
                throw new LogicException('Trainer scope not active on a portal request');
            }
        }
        $visible = $planning->teams($actor, $tenant, true)->pluck('id');
        if ($current->diff($visible)->isNotEmpty() || $visible->intersect($old)->isNotEmpty()) {
            throw new LogicException('Current/historical team access mismatch');
        }
        if ($historicalEvent !== null) {
            $fetch('/planning/'.$tenant->slug.'/events/'.$historicalEvent, 404);
        }
        $export = $fetch('/planning/'.$tenant->slug.'/calendar/export', 200);
        if (! str_contains($export->getContent(), 'BEGIN:VCALENDAR')) {
            throw new LogicException('Invalid calendar export');
        }
        echo json_encode(['real_dev_trainer' => true, 'read_only' => true, 'portal_pages' => 4, 'de_en' => true,
            'current_teams' => $visible->count(), 'historical_assignments_excluded' => $old->count(),
            'historical_direct_link_404' => $historicalEvent !== null, 'calendar_export' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $db->rollBack();
    }
} catch (Throwable $error) {
    $description = $error instanceof LogicException ? $error->getMessage() : get_class($error);
    fwrite(STDERR, 'Trainer DEV gate failed: '.$description.PHP_EOL);
    exit(1);
}
