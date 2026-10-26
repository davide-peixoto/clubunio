<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\TrainingPlanPdfService;
use App\ClubUnio\Services\TrainingPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrainingPlanController
{
    private function context(Request $request, Tenant $tenant): array
    {
        $actor = $request->user();
        $teams = app(TrainingPlanService::class)->teams($actor, $tenant);
        $managed = $teams->pluck('id');

        return compact('actor', 'tenant', 'teams', 'managed');
    }

    public function index(Request $request, Tenant $tenant)
    {
        $context = $this->context($request, $tenant);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'team' => 'nullable|integer', 'archived' => 'nullable|in:0,1']);
        $plans = app(TrainingPlanService::class)->visible($context['actor'], $tenant)
            ->select(['id', 'title', 'team_season_id', 'shared', 'updated_at'])
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where('title', 'ilike', '%'.$value.'%'))
            ->when($filters['team'] ?? null, fn ($q, $value) => $q->where('team_season_id', $value))
            ->orderByDesc('updated_at')->paginate(20)->withQueryString();

        return response()->view('clubunio.training.index', $context + compact('plans'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, Tenant $tenant)
    {
        app(TrainingPlanService::class)->authorizeOperation($request->user(), $tenant, 'create');
        $context = $this->context($request, $tenant);
        $request->validate(['season' => 'nullable|integer']);
        $seasons = DB::connection('clubunio')->table('clubora.seasons')->where('tenant_id', $tenant->id)
            ->whereIn('id', $context['teams']->pluck('season_id'))->orderByDesc('name')->get();
        $selectedSeason = (int) $request->input('season', $seasons->first()?->id);
        $context['teams'] = $context['teams']->where('season_id', $selectedSeason);

        return response()->view('clubunio.training.create', $context + compact('seasons', 'selectedSeason') + ['requestKey' => (string) Str::uuid()])->header('Cache-Control', 'private, no-store');
    }

    public function generate(Request $request, Tenant $tenant)
    {
        $id = app(TrainingPlanService::class)->generate($request->user(), $tenant, $request->all(), (string) $request->input('request_key'));

        return redirect()->route('clubunio.training.show', [$tenant->slug, $id])->with('notice', 'Trainingsplan erstellt.');
    }

    public function show(Request $request, Tenant $tenant, int $plan)
    {
        $context = $this->context($request, $tenant);
        $row = app(TrainingPlanService::class)->get($context['actor'], $tenant, $plan);
        $canManage = app(ModulePermissionService::class)->allows($context['actor'], $tenant, 'training', 'update')
            || (in_array(app(MemberAccessService::class)->membership($context['actor']->fresh(), $tenant)?->role, ['trainer', 'technical_lead'], true) && $row->owner_id === $context['actor']->id);
        $editable = $canManage && $row->archived_at === null;
        $review = $request->session()->get($this->key($tenant, $plan));
        if ($review && ($review['expires'] < time() || $review['actor'] !== $context['actor']->id || $review['revision'] !== $row->revision)) {
            $request->session()->forget($this->key($tenant, $plan));
            $review = null;
        }

        return response()->view('clubunio.training.show', $context + compact('row', 'editable', 'canManage', 'review') + ['requestKey' => (string) Str::uuid()])->header('Cache-Control', 'private, no-store');
    }

    public function archive(Request $request, Tenant $tenant, int $plan)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'archived' => 'required|boolean']);
        app(TrainingPlanService::class)->archive($request->user(), $tenant, $plan, (int) $data['revision'], $request->boolean('archived'));
        $request->session()->forget($this->key($tenant, $plan));

        return redirect()->route('clubunio.training.show', [$tenant->slug, $plan])->with('notice', 'Planstatus gespeichert.');
    }

    public function copy(Request $request, Tenant $tenant, int $plan)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'request_key' => 'required|uuid']);
        $id = app(TrainingPlanService::class)->copy($request->user(), $tenant, $plan, (int) $data['revision'], $data['request_key']);

        return redirect()->route('clubunio.training.show', [$tenant->slug, $id])->with('notice', 'Private Kopie erstellt.');
    }

    public function save(Request $request, Tenant $tenant, int $plan)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'plan' => 'required|array', 'shared' => 'sometimes|boolean']);
        foreach (['principles', 'qualityChecks'] as $field) {
            if (is_string($data['plan'][$field] ?? null)) {
                $data['plan'][$field] = $this->lines($data['plan'][$field]);
            }
        }
        foreach ($data['plan']['exercises'] ?? [] as $i => $exercise) {
            if (is_string($exercise['coachingPoints'] ?? null)) {
                $data['plan']['exercises'][$i]['coachingPoints'] = $this->lines($exercise['coachingPoints']);
            }
        }
        app(TrainingPlanService::class)->save($request->user(), $tenant, $plan, (int) $data['revision'], $data['plan'], $request->boolean('shared'));

        return redirect()->route('clubunio.training.show', [$tenant->slug, $plan])->with('notice', 'Trainingsplan gespeichert.');
    }

    public function review(Request $request, Tenant $tenant, int $plan)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'request_key' => 'required|uuid']);
        $review = app(TrainingPlanService::class)->review($request->user(), $tenant, $plan, (int) $data['revision'], $data['request_key']);
        $request->session()->put($this->key($tenant, $plan), $review);

        return redirect()->route('clubunio.training.show', [$tenant->slug, $plan]);
    }

    public function apply(Request $request, Tenant $tenant, int $plan)
    {
        app(TrainingPlanService::class)->authorizeOperation($request->user(), $tenant, 'approve');
        $review = $request->session()->get($this->key($tenant, $plan));
        abort_unless($review && $review['expires'] >= time() && $review['actor'] === $request->user()->id, 409);
        $service = app(TrainingPlanService::class);
        $row = $service->get($request->user(), $tenant, $plan, true);
        $service->save($request->user(), $tenant, $plan, $review['revision'], $review['plan'], $row->shared);
        $request->session()->forget($this->key($tenant, $plan));

        return redirect()->route('clubunio.training.show', [$tenant->slug, $plan])->with('notice', 'Prüfvorschlag übernommen.');
    }

    public function pdf(Request $request, Tenant $tenant, int $plan)
    {
        return app(TrainingPlanPdfService::class)->open($request->user(), $tenant, $plan);
    }

    private function key(Tenant $tenant, int $id): string
    {
        return 'training_review.'.$tenant->id.'.'.$id;
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $value)), fn ($v) => $v !== ''));
    }
}
