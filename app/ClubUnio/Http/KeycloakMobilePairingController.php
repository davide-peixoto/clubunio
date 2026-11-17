<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Mobile\{MobileBootstrapState, MobileSessions};
use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\{AccountSecurity, KeycloakMobileAssurance};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class KeycloakMobilePairingController
{
    public function __construct(private AccountSecurity $security) {}

    private function pairs() { return $this->security->db()->table('clubora.account_security_pairs'); }

    public static function available(string $origin): bool
    {
        return config('clubunio-security.enabled') === true && config('clubunio-security.origin') === $origin
            && config('clubunio-verein-oidc.origin') === $origin && KeycloakMobileAssurance::level() !== null;
    }

    public function pair(Request $request, string $operation)
    {
        abort_unless($request->isJson() && $request->query->count() === 0, 415);
        abort_unless(self::available($this->security->origin($request)), 404);
        app(MobileBootstrapState::class)->authorizeLogin($request);
        if ($operation === 'begin') {
            $data = $request->validate(['proof_hash' => 'required|regex:/^[a-f0-9]{64}$/D']);
            $id = (string) Str::uuid();
            $native = $request->validate(['native' => 'sometimes|boolean'])['native'] ?? false;
            $confirmation = $native ? bin2hex(random_bytes(32)) : (string) random_int(10000000, 99999999);
            $this->pairs()->where('expires_at', '<', now())->delete();
            $this->pairs()->insert(['id' => $id, 'proof_hash' => $data['proof_hash'],
                'confirmation_hash' => $this->pairHash($id, $confirmation), 'expires_at' => now()->addMinutes(5)]);
            return response()->json(['data' => ['pair' => $id, 'confirmation' => $confirmation]])->header('Cache-Control', 'private, no-store');
        }
        abort_unless($operation === 'redeem', 404);
        $data = $request->validate(['pair' => 'required|uuid', 'proof' => 'required|regex:/^[a-f0-9]{64}$/D',
            'return_secret' => 'sometimes|required|regex:/^[a-f0-9]{64}$/D']);
        $result = $this->security->db()->transaction(function () use ($request, $data) {
            $query = $this->pairs()->where('id', $data['pair'])->where('expires_at', '>', now())->whereNull('consumed_at');
            $row = (clone $query)->lockForUpdate()->first();
            $proofHash = hash('sha256', $data['proof']);
            if (isset($data['return_secret'])) {
                $proofHash = hash('sha256', $proofHash.'|'.$data['return_secret']);
            }
            abort_unless($row && hash_equals($row->proof_hash, $proofHash), 404);
            if (!$row->account_id) return ['data' => ['pending' => true]];
            $actor = Account::whereKey($row->account_id)->where('active', true)->where('api_only', false)->firstOrFail();
            $stamp = app(KeycloakMobileAssurance::class)->stamp($actor);
            abort_unless($stamp !== null && is_string($row->session_stamp) && hash_equals($stamp, $row->session_stamp), 403);
            $result = app(MobileSessions::class)->issue($request, $actor, 'ClubUnio App', strongStamp: $stamp);
            $query->update(['consumed_at' => now()]);
            return ['data' => $result];
        });
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function pairHash(string $id, string $confirmation): string
    {
        return hash_hmac('sha256', $id.'|'.$confirmation, (string) config('app.key'));
    }

    public function validPair(Request $request, bool $native = false): void
    {
        abort_unless(self::available($this->security->origin($request)), 404);
        $data = $request->validate(['pair' => 'required|uuid',
            'confirmation' => $native ? 'required|regex:/^[a-f0-9]{64}$/D' : 'required|regex:/^\d{8}$/D']);
        $this->security->rate('pair', $data['pair'], 12, 300);
        abort_unless($this->pairs()->where('id', $data['pair'])->where('confirmation_hash', $this->pairHash($data['pair'], $data['confirmation']))
            ->where('expires_at', '>', now())->whereNull('account_id')->whereNull('consumed_at')->exists(), 422);
    }

    public function complete(array $pair, Account $actor, \stdClass $claims): ?string
    {
        $stamp = app(KeycloakMobileAssurance::class)->stamp($actor, $claims);
        abort_unless($stamp !== null, 403);
        $returnSecret = ($pair['native'] ?? false) ? bin2hex(random_bytes(32)) : null;
        $query = $this->pairs()->where('id', $pair['pair'])
            ->where('confirmation_hash', $this->pairHash($pair['pair'], $pair['confirmation']))
            ->where('expires_at', '>', now())->whereNull('account_id')->whereNull('consumed_at');
        $this->security->db()->transaction(function () use ($query, $returnSecret, $actor, $stamp) {
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 403);
            $query->update(['account_id' => $actor->id, 'session_stamp' => $stamp,
                'proof_hash' => $returnSecret === null ? $row->proof_hash : hash('sha256', $row->proof_hash.'|'.$returnSecret)]);
        });
        return $returnSecret;
    }
}
