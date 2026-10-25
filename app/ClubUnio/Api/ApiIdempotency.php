<?php

namespace App\ClubUnio\Api;

use App\ClubUnio\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ApiIdempotency
{
    public function execute(ApiContext $context, Request $request, Closure $operation, ?array $fingerprint = null)
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key), 422);
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$context->authorizationFingerprint().' '.json_encode($fingerprint ?? $request->json()->all(), JSON_THROW_ON_ERROR));
        $db = DB::connection('clubunio');
        $keyHash = hash('sha256', $key);
        $id = $db->table('clubora.api_idempotency')->insertOrIgnore([
            'integration_id' => $context->integration->id, 'key_hash' => $keyHash, 'payload_hash' => $hash, 'state' => 'processing',
        ]);
        if (! $id) {
            $previous = $db->table('clubora.api_idempotency')->where('integration_id', $context->integration->id)->where('key_hash', $keyHash)->first();
            abort_unless(hash_equals($previous->payload_hash, $hash), 409);
            abort_unless($previous->state === 'complete', 409);

            return response()->json(json_decode(Crypt::decryptString($previous->response_encrypted), true, flags: JSON_THROW_ON_ERROR), $previous->status)
                ->header('Idempotency-Replayed', 'true');
        }
        try {
            // Only local transactional services belong here; external writes need a durable outbox.
            return $db->transaction(function () use ($db, $context, $keyHash, $operation) {
                Tenant::whereKey($context->tenant->id)->lockForUpdate()->firstOrFail();
                $context->authorizationFingerprint();
                $response = $operation();
                $db->table('clubora.api_idempotency')->where('integration_id', $context->integration->id)->where('key_hash', $keyHash)->update([
                    'state' => 'complete', 'status' => $response->getStatusCode(),
                    'response_encrypted' => Crypt::encryptString($response->getContent()),
                ]);

                return $response;
            });
        } catch (\Throwable $error) {
            // Failed/abandoned attempts cannot replay a possibly partially completed side effect.
            $db->table('clubora.api_idempotency')->where('integration_id', $context->integration->id)->where('key_hash', $keyHash)->update(['state' => 'uncertain']);
            throw $error;
        }
    }
}
