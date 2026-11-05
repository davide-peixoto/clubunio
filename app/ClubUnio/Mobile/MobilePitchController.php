<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PitchPlanningService;
use App\ClubUnio\Services\PitchFriendlyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};

final class MobilePitchController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array
    {
        return [MobilePitchReady::class];
    }

    public function options(Request $request): JsonResponse
    {
        $this->input($request, []);
        return response()->json(['data' => app(PitchPlanningService::class)->options(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'))]);
    }

    public function calendar(Request $request): JsonResponse
    {
        return response()->json(['data' => app(PitchPlanningService::class)->calendar(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'),
            $this->input($request, $this->calendarRules()))]);
    }

    public function teamCalendar(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        $team = (int) $request->route('team');
        app(MobileTeamsData::class)->team($actor, $tenant, $team);
        $rules = $this->calendarRules();
        unset($rules['team_season_id']);
        $filters = $this->input($request, $rules) + ['team_season_id' => $team];
        return response()->json(['data' => app(PitchPlanningService::class)->calendar($actor, $tenant, $filters)]);
    }

    public function requests(Request $request): JsonResponse
    {
        return response()->json(['data' => app(PitchPlanningService::class)->requests(
            $request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'),
            $this->input($request, [
                'page' => 'sometimes|integer|min:1|max:2147483647',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|required|in:pending,approved,rejected,cancelled',
            ]))]);
    }

    public function request(Request $request): JsonResponse
    {
        return $this->write($request, function ($actor, $tenant, $service) use ($request) {
            $rules = $this->placementRules();
            $rules['duration_minutes'] = 'sometimes|integer|min:1|max:1440';
            $data = $this->input($request, $rules + [
                'team_season_id' => 'required|integer|min:1', 'opponent' => 'required|string|max:255',
                'notes' => 'nullable|string|max:2000',
                'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'],
            ]);
            $requestId = $data['request_id'];
            unset($data['request_id']);
            $result = app(PitchFriendlyRequestService::class)->submit($actor, $tenant, $data, $requestId);
            $response = response()->json(['data' => $result['data']]);
            if ($result['replayed']) $response->header('Idempotency-Replayed', 'true');
            return $response;
        });
    }

    public function move(Request $request): JsonResponse
    {
        return $this->write($request, function ($actor, $tenant, $service) use ($request) {
            $rules = $this->placementRules();
            // The core applies the source-specific limit; multi-day blocks can be moved.
            $rules['duration_minutes'] = 'required|integer|min:1|max:44640';
            $data = $this->input($request, $rules + [
                'key' => 'required|string|max:160', 'revision' => 'required|string|max:200',
            ]);
            $key = $data['key'];
            unset($data['key']);
            return $service->move($actor, $tenant, $key, $data);
        });
    }

    public function review(Request $request): JsonResponse
    {
        return $this->write($request, fn ($actor, $tenant, $service) => $service->decide(
            $actor, $tenant, (int) $request->route('booking'), $this->input($request, [
                'revision' => 'required|string|max:200', 'decision' => 'required|in:approved,rejected',
            ])));
    }

    public function competitionTeams(Request $request): JsonResponse
    {
        return $this->write($request, fn ($actor, $tenant, $service) => $service->configureCompetition(
            $actor, $tenant, (int) $request->route('competition'), $this->input($request, [
                'revision' => 'required|string|max:200', 'team_season_ids' => 'present|array|list|max:20',
                'team_season_ids.*' => 'integer|distinct|min:1',
            ])));
    }

    private function write(Request $request, callable $operation): JsonResponse
    {
        // Match the personal API's lock order; the domain owns business transactions.
        return DB::connection('clubunio')->transaction(function () use ($request, $operation) {
            $tenant = $request->attributes->get('mobile.tenant');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            DB::connection('clubunio')->table('clubora.mobile_sessions')
                ->where('id', $request->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
            $actor = app(MobileSessions::class)->authenticate($request);
            app(MobileAccess::class)->enter($actor, $tenant);
            $result = $operation($actor, $tenant, app(PitchPlanningService::class));
            return $result instanceof JsonResponse ? $result : response()->json(['data' => $result]);
        });
    }

    private function input(Request $request, array $rules): array
    {
        $data = $request->all();
        $fields = array_filter(array_keys($rules), fn (string $key) => ! str_contains($key, '.'));
        // Root allowlist also rejects client-supplied tenant, actor, approval and SFV authority.
        if (array_diff(array_keys($data), $fields)) {
            Validator::make(['payload' => $data], ['payload' => 'array:'.implode(',', $fields)])->validate();
        }
        return Validator::make($data, $rules, [], ['request_id' => __('Kennung')])->validate();
    }

    private function calendarRules(): array
    {
        return ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'period_id' => 'sometimes|integer|min:1', 'team_season_id' => 'sometimes|integer|min:1'];
    }

    private function placementRules(): array
    {
        return ['field_id' => 'required|integer|min:1', 'area_id' => 'nullable|integer|min:1',
            'date' => 'required|date_format:Y-m-d', 'starts_at' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:1|max:1440'];
    }
}
