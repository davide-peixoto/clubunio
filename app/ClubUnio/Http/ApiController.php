<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiIdempotency;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use Illuminate\Http\Request;

final class ApiController
{
    private function contextFor(Request $request): ApiContext
    {
        return $request->attributes->get('clubunio.api.context');
    }

    public function context(Request $request)
    {
        $context = $this->contextFor($request);

        return response()->json(['data' => ['integration_id' => $context->integration->id,
            'name' => $context->integration->name, 'tenant' => ['id' => $context->tenant->id, 'name' => $context->tenant->name],
            'scopes' => array_values(array_intersect($context->integration->scopes, $context->token->abilities)),
            'expires_at' => $context->token->expires_at->toIso8601String(), 'api_version' => 'v1']]);
    }

    public function index(Request $request, string $resource, ApiModuleRegistry $registry)
    {
        return response()->json($registry->list($this->contextFor($request), $resource, $request->query()));
    }

    public function show(Request $request, string $resource, int $id, ApiModuleRegistry $registry)
    {
        abort_if($request->query(), 422);

        return response()->json(['data' => $registry->show($this->contextFor($request), $resource, $id)]);
    }

    public function create(Request $request, string $resource, ApiModuleRegistry $registry)
    {
        return $this->write($request, $resource, 'create', fn ($context) => response()->json(['data' => $registry->create($context, $resource, $request->json()->all())], 201), $registry);
    }

    public function update(Request $request, string $resource, int $id, ApiModuleRegistry $registry)
    {
        return $this->write($request, $resource, 'update', fn ($context) => response()->json(['data' => $registry->update($context, $resource, $id, $request->json()->all())]), $registry);
    }

    public function action(Request $request, string $resource, int $id, string $action, ApiModuleRegistry $registry)
    {
        return $this->write($request, $resource, $action, fn ($context) => response()->json(['data' => $registry->action($context, $resource, $id, $action, $request->json()->all())]), $registry);
    }

    private function write(Request $request, string $resource, string $operation, \Closure $callback, ApiModuleRegistry $registry)
    {
        abort_unless($request->isJson(), 415);
        abort_if($request->query(), 422);
        abort_if(strlen($request->getContent()) > 1_048_576, 413);
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
            abort_unless($body instanceof \stdClass, 422);
        } catch (\JsonException) {
            abort(422);
        }
        $context = $this->contextFor($request);
        $registry->authorize($context, $resource, $operation);

        return app(ApiIdempotency::class)->execute($context, $request, function () use ($registry, $context, $resource, $operation, $callback) {
            $registry->authorize($context, $resource, $operation);

            return $callback($context);
        });
    }
}
