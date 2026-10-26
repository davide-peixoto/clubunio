<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiIdempotency;
use App\ClubUnio\Api\ApiProviderWork;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ApiProviderController
{
    public function schedule(Request $request)
    {
        $c = $request->attributes->get('clubunio.api.context');
        $c->authorize('competitions:read', 'competitions', 'view');
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page', 'from', 'to']), 422);
        $data = Validator::make($request->query(), ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d'.(isset($request->query()['from']) ? '|after_or_equal:from' : '')])->validate();
        $fields = ['id', 'match_id', 'season_id', 'team_a_id', 'team_b_id', 'host_name', 'guest_name', 'title', 'starts_at', 'location', 'state', 'match_type', 'home', 'revision', 'updated_at'];
        $rows = DB::connection('clubunio')->table('clubora.sfv_schedule')->where('tenant_id', $c->tenant->id)
            ->when($data['from'] ?? null, fn ($q, $value) => $q->where('starts_at', '>=', CarbonImmutable::parse($value, 'Europe/Zurich')->startOfDay()->toIso8601String()))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->where('starts_at', '<', CarbonImmutable::parse($value, 'Europe/Zurich')->addDay()->startOfDay()->toIso8601String()))
            ->orderBy('starts_at')->orderBy('id')->paginate($data['per_page'] ?? 30, $fields, 'page', $data['page'] ?? 1);
        $records = $rows->getCollection()->map(function ($row) {
            $row->starts_at = $row->starts_at ? CarbonImmutable::parse($row->starts_at)->toIso8601String() : null;
            $row->updated_at = CarbonImmutable::parse($row->updated_at)->toIso8601String();

            return (array) $row;
        });

        return response()->json(['data' => $records, 'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function stage(Request $request, string $operation, ApiProviderWork $work)
    {
        $this->json($request);
        abort_if(array_diff(array_keys($request->json()->all()), ['object_id', 'input']), 422);
        $data = Validator::make($request->json()->all(), ['object_id' => 'required|integer|min:0|max:999999999999999999', 'input' => 'present|array'])->validate();
        // An object, including {}, is required for input; lists are never accepted.
        abort_unless(json_decode($request->getContent())->input instanceof \stdClass, 422);
        $c = $request->attributes->get('clubunio.api.context');
        app(ProviderApiRegistry::class)->authorize($c, $operation);

        return app(ApiIdempotency::class)->execute($c, $request,
            fn () => response()->json(['data' => $work->stage($c, $operation, $data['object_id'], $data['input'])], 202));
    }

    public function show(Request $request, int $id, ApiProviderWork $work)
    {
        abort_if($request->query(), 422);

        return response()->json(['data' => $work->show($request->attributes->get('clubunio.api.context'), $id)]);
    }

    public function process(Request $request, int $id, ApiProviderWork $work)
    {
        $this->json($request);
        abort_if($request->json()->all(), 422);

        return response()->json(['data' => $work->process($request->attributes->get('clubunio.api.context'), $id)]);
    }

    private function json(Request $request): void
    {
        abort_unless($request->isJson(), 415);
        abort_if($request->query(), 422);
        abort_if(strlen($request->getContent()) > 1_048_576, 413);
        try {
            abort_unless(json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR) instanceof \stdClass, 422);
        } catch (\JsonException) {
            abort(422);
        }
    }
}
