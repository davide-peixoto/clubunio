<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\TrainingPlanPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileTrainingController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileTrainingReady::class]; }
    public function index(Request $request)
    {
        [$actor, $tenant, $service] = $this->context($request);
        $d = $request->validate(['start' => 'sometimes|integer|min:0|max:100000', 'limit' => 'sometimes|integer|min:1|max:50']);
        $rows = $service->visible($actor, $tenant)->whereNull('archived_at')->orderByDesc('created_at')->orderByDesc('id')
            ->offset($d['start'] ?? 0)->limit($d['limit'] ?? 20)->get(['id','title','team_season_id','shared','input_json','created_at']);
        return response()->json(['data' => ['trainingPlans' => $rows->map(function ($row) use ($actor, $tenant, $service) {
            $input = json_decode($row->input_json, true, 64, JSON_THROW_ON_ERROR);
            return ['id' => (int) $row->id, 'title' => $row->title, 'teamName' => $input['teamName'],
                'durationMinutes' => (int) $input['durationMinutes'], 'playerCount' => (int) $input['playerCount'],
                'createdAt' => $row->created_at, 'hasPdf' => $service->can($actor, $tenant, 'download'),
                'sharedTeamSaisonId' => $service->sharedTeam($tenant, $row)];
        })->all()]]);
    }
    public function options(Request $request)
    {
        [$actor, $tenant, $service] = $this->context($request);
        $teams = $service->teams($actor, $tenant);
        return response()->json(['data' => ['teams' => $teams->map(fn ($t) => ['id' => (int) $t->id, 'label' => $t->label])->all(),
            'canGenerate' => $teams->isNotEmpty() && $service->can($actor, $tenant, 'create') && app(MobileTrainingWork::class)->available($tenant)]]);
    }
    public function show(Request $request)
    {
        [$actor, $tenant, $service] = $this->context($request);
        return response()->json(['data' => app(MobileTrainingData::class)->plan($actor, $tenant, $service->get($actor, $tenant, (int) $request->route('plan')))]);
    }
    public function save(Request $request)
    {
        return $this->write($request, function ($actor, $tenant, $service) use ($request) {
            $d = $request->validate(['revision' => 'required|integer|min:1']);
            $row = $service->get($actor, $tenant, (int) $request->route('plan'), true);
            abort_unless((int) $row->revision === (int) $d['revision'], 409, 'Plan wurde geändert. Bitte neu laden.');
            [$draft, $input] = app(MobileTrainingData::class)->changes($row, $request->all());
            $service->save($actor, $tenant, $row->id, $d['revision'], $draft, (bool) $row->shared, $input);
            return response()->json(['data' => app(MobileTrainingData::class)->plan($actor, $tenant, $service->get($actor, $tenant, $row->id))]);
        });
    }
    public function share(Request $request)
    {
        return $this->write($request, function ($actor, $tenant, $service) use ($request) {
            $d = $request->validate(['revision' => 'required|integer|min:1', 'teamSaisonId' => 'present|nullable|integer|min:1']);
            $row = $service->get($actor, $tenant, (int) $request->route('plan'), true);
            abort_unless($service->can($actor, $tenant, 'send', $row), 403);
            abort_unless((int) $row->revision === (int) $d['revision'], 409, 'Plan wurde geändert. Bitte neu laden.');
            $target = $d['teamSaisonId'];
            abort_unless($target === null || $service->teams($actor, $tenant)->contains('id', $target), 403);
            $before = $service->sharedTeam($tenant, $row);
            $service->save($actor, $tenant, $row->id, $d['revision'], $row->plan, $target !== null);
            $q = DB::connection('clubunio')->table('clubora.mobile_training_plan_shares')->where('tenant_id', $tenant->id)->where('plan_id', $row->id);
            $q->delete();
            if ($target !== null) { DB::connection('clubunio')->table('clubora.mobile_training_plan_shares')->insert([
                'tenant_id' => $tenant->id, 'plan_id' => $row->id, 'team_season_id' => $target, 'shared_by' => $actor->id, 'created_at' => now()]); }
            DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'training_plan', 'object_id' => $row->id,
                'before_value' => json_encode(['revision' => $d['revision'], 'shared_team_season_id' => $before], JSON_THROW_ON_ERROR),
                'after_value' => json_encode(['revision' => $d['revision'] + 1, 'shared_team_season_id' => $target, 'event' => 'native_team_share'], JSON_THROW_ON_ERROR),
            ]);
            return response()->json(['data' => app(MobileTrainingData::class)->plan($actor, $tenant, $service->get($actor, $tenant, $row->id))]);
        });
    }
    public function pdf(Request $request)
    {
        return $this->write($request, function ($actor, $tenant, $service) use ($request) {
            $d = $request->validate(['revision' => 'required|integer|min:1']);
            $row = $service->get($actor, $tenant, (int) $request->route('plan'));
            abort_unless((int) $row->revision === (int) $d['revision'], 409, 'Plan wurde geändert. Bitte neu laden.');
            $response = app(TrainingPlanPdfService::class)->open($actor, $tenant, $row->id, $service);
            $bytes = $response->getContent();
            abort_unless(strlen($bytes) <= 20 * 1024 * 1024, 413);
            return $response->header('Content-Length', (string) strlen($bytes))->header('X-Content-SHA256', hash('sha256', $bytes))
                ->header('X-Training-Revision', (string) $row->revision);
        });
    }
    public function generate(Request $request) { return $this->write($request, fn ($actor, $tenant) => app(MobileTrainingWork::class)->reserve($request, $actor, $tenant, 'training_generate')); }
    public function review(Request $request) { return $this->write($request, fn ($actor, $tenant) => app(MobileTrainingWork::class)->reserve($request, $actor, $tenant, 'training_review')); }
    public function work(Request $request)
    {
        [$actor, $tenant] = $this->context($request);
        return response()->json(['data' => app(MobileTrainingWork::class)->read($actor, $tenant, (string) $request->route('work'))]);
    }
    public function apply(Request $request)
    {
        return $this->write($request, fn ($actor, $tenant) => app(MobileTrainingWork::class)->apply($request, $actor, $tenant));
    }
    private function context(Request $request): array { return [$request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'), app(MobileTrainingPlanService::class)]; }
    private function write(Request $request, \Closure $action)
    {
        return DB::connection('clubunio')->transaction(function () use ($request, $action) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            return $action($actor, $tenant, app(MobileTrainingPlanService::class));
        });
    }
}
