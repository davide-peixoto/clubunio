<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Schema, Validator};

/** Idempotency for personal API and web friendly requests, never manual bookings. */
final class PitchFriendlyRequestService
{
    public function submit(Account $actor, Tenant $tenant, array $input, string $requestId): array
    {
        Validator::make(['request_id' => $requestId], [
            'request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'],
        ], [], ['request_id' => __('Kennung')])->validate();
        $rules = ['team_season_id' => 'required|integer|min:1', 'field_id' => 'required|integer|min:1',
            'area_id' => 'nullable|integer|min:1', 'date' => 'required|date_format:Y-m-d',
            'starts_at' => 'required|date_format:H:i', 'duration_minutes' => 'integer|min:1|max:1440',
            'opponent' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000'];
        Validator::make(['payload' => $input], ['payload' => 'array:'.implode(',', array_keys($rules))])->validate();
        $data = Validator::make($input + ['area_id' => null, 'duration_minutes' => 120, 'notes' => null], $rules)->validate();
        foreach (['team_season_id', 'field_id', 'area_id', 'duration_minutes'] as $key) {
            if ($data[$key] !== null) $data[$key] = (int) $data[$key];
        }
        ksort($data);
        $payloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $requestId);
        abort_unless(Schema::connection('clubunio')->hasTable('clubora.pitch_request_idempotency'), 503);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data, $keyHash, $payloadHash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $ledger = DB::connection('clubunio')->table('clubora.pitch_request_idempotency')
                ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('key_hash', $keyHash);
            $service = app(PitchPlanningService::class);
            $previous = $ledger->first();
            if ($previous) {
                // Replays require current domain authority, but never execute its mutation.
                $service->authorizeFriendlyRequest($actor, $tenant, $data['team_season_id']);
                abort_unless(hash_equals($previous->payload_hash, $payloadHash), 409);
                return ['data' => json_decode(Crypt::decryptString($previous->response_encrypted), true, flags: JSON_THROW_ON_ERROR), 'replayed' => true];
            }
            $result = $service->requestFriendly($actor, $tenant, $data);
            // The result, request and notification intent commit together or not at all.
            $ledger->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'key_hash' => $keyHash,
                'payload_hash' => $payloadHash, 'response_encrypted' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR))]);
            return ['data' => $result, 'replayed' => false];
        });
    }
}
