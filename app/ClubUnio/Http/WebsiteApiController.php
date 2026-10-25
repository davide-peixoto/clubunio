<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\FieldAvailability;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TrainingSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class WebsiteApiController
{
    public function index(Request $request, string $feed)
    {
        /** @var ApiContext $context */
        $context = $request->attributes->get('clubunio.api.context');
        [$module, $function] = ApiTokenService::WEBSITE['website:'.$feed.':read'];
        $context->authorize('website:'.$feed.':read', $module, $function);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page', 'season_id', 'from', 'to']), 422);
        $input = Validator::make($request->query(), ['page' => 'sometimes|integer|min:1|max:10000',
            'per_page' => 'sometimes|integer|min:1|max:100', 'season_id' => 'sometimes|integer|min:1',
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => ['sometimes', 'date_format:Y-m-d', ...($request->has('from') ? ['after_or_equal:from'] : [])]])->validate();
        $tenant = $context->tenant->id;
        if ($feed === 'teams') {
            $query = TeamSeason::where('tenant_id', $tenant)->where('active', true)
                ->whereHas('team', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true))
                ->whereHas('season', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true))
                ->with(['team', 'season']);
            if (isset($input['season_id'])) {
                $query->where('season_id', $input['season_id']);
            }
            $map = fn ($row) => ['id' => $row->id, 'team_id' => $row->team_id, 'season_id' => $row->season_id,
                'name' => $row->team->name, 'label' => $row->label, 'season' => $row->season->name];
        } elseif ($feed === 'fixtures') {
            $query = Competition::where('tenant_id', $tenant)->where('active', true)->where('show_on_signage', true)
                ->whereBetween('plays_on', [$input['from'] ?? now('Europe/Zurich')->toDateString(), $input['to'] ?? now('Europe/Zurich')->addMonths(3)->toDateString()])
                ->orderBy('plays_on')->orderBy('starts_at')
                ->with(['field', 'hostRoom', 'guestRoom']);
            $map = fn ($row) => ['id' => $row->id, 'date' => $row->plays_on?->format('Y-m-d'), 'time' => $row->starts_at,
                'host' => $row->host_name, 'guest' => $row->guest_name, 'host_club_number' => $row->host_club_number,
                'guest_club_number' => $row->guest_club_number, 'field' => $row->field?->name,
                'host_room' => $row->hostRoom?->name, 'guest_room' => $row->guestRoom?->name];
        } elseif ($feed === 'training-times') {
            $query = TrainingSlot::where('tenant_id', $tenant)->where('active', true)->where('website_visible', true)
                ->whereHas('teamSeason', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true)
                    ->whereHas('team', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true))
                    ->whereHas('season', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true)))
                ->with(['teamSeason.team', 'weekday', 'period', 'field', 'room']);
            if (isset($input['season_id'])) {
                $query->whereHas('teamSeason', fn ($q) => $q->where('season_id', $input['season_id']));
            }
            $map = fn ($row) => ['id' => $row->id, 'team_season_id' => $row->team_season_id, 'team' => $row->teamSeason?->team?->name,
                'weekday' => $row->weekday?->name, 'period' => $row->period?->name, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at,
                'field' => $row->field?->name, 'room' => $row->room?->name];
        } else {
            $query = FieldAvailability::where('tenant_id', $tenant)->where('active', true)
                ->whereHas('field', fn ($q) => $q->where('tenant_id', $tenant)->where('active', true))
                ->with(['field', 'status']);
            $map = fn ($row) => ['id' => $row->id, 'field' => $row->field?->name, 'status' => $row->status?->name,
                'blocks_training' => ! $row->status?->active || $row->status->blocks_training];
        }
        $page = $query->orderBy('id')->paginate($input['per_page'] ?? 30);

        return response()->json(['data' => $page->getCollection()->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }
}
