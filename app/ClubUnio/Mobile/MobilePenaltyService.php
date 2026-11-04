<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\MemberAccessService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

final class MobilePenaltyService
{
    private function table(Tenant $tenant, string $name) { return DB::connection('clubunio')->table('clubora.mobile_penalty_'.$name)->where('tenant_id', $tenant->id); }
    public function options(Account $actor, Tenant $tenant): array
    {
        app(MobilePenaltyAccess::class)->authorize($actor, $tenant);
        return ['allowed' => true, 'canReset' => app(MobilePenaltyAccess::class)->canReset($actor, $tenant), 'tenantId' => (int) $tenant->id, 'tenantName' => $tenant->name];
    }
    private function generation(Tenant $tenant, bool $initialize): int
    {
        if ($initialize) $this->table($tenant, 'challenges')->insertOrIgnore(['tenant_id' => $tenant->id, 'generation' => 1]);
        return (int) ($this->table($tenant, 'challenges')->value('generation') ?? 1);
    }
    /** Caller holds the tenant lock, authenticated session and transaction. */
    public function start(Account $actor, Tenant $tenant, string $mode, string $command): array
    {
        app(MobilePenaltyAccess::class)->authorize($actor, $tenant);
        $generation = $this->generation($tenant, true);
        $old = $this->table($tenant, 'sessions')->where('account_id', $actor->id)->where('command_key', $command)->first();
        if ($old) {
            abort_unless($old->mode === $mode && (int) $old->generation === $generation && ! $old->finished_at && Time::parse($old->expires_at)->isFuture(), 409, 'Runde wurde bereits geaendert.');
            return ['success' => true, 'sessionId' => $old->id, 'opponents' => json_decode(Crypt::decryptString($old->opponents_encrypted), true, 32, JSON_THROW_ON_ERROR), 'replayed' => true];
        }
        $expired = $this->table($tenant, 'sessions')->where('expires_at', '<', Time::now()->subDays(2)->toIso8601String())->orderBy('id')->limit(200)->pluck('id');
        if ($expired->isNotEmpty()) $this->table($tenant, 'sessions')->whereIn('id', $expired)->delete();
        abort_if($this->table($tenant, 'sessions')->where('account_id', $actor->id)->where('created_at', '>', Time::now()->subMinutes(10)->toIso8601String())->count() >= 30, 429, 'Kurze Ranglistenpause.');
        abort_if($this->table($tenant, 'sessions')->count() >= 100000, 429, 'Ranglistenrunden sind derzeit ausgelastet.');
        $opponents = array_map(fn () => random_int(0, 2), range(1, 10)); $id = bin2hex(random_bytes(24)); $now = Time::now();
        $this->table($tenant, 'sessions')->insert(['tenant_id' => $tenant->id, 'id' => $id, 'account_id' => $actor->id, 'command_key' => $command,
            'generation' => $generation, 'mode' => $mode, 'opponents_encrypted' => Crypt::encryptString(json_encode($opponents, JSON_THROW_ON_ERROR)),
            'created_at' => $now->toIso8601String(), 'expires_at' => $now->addMinutes(30)->toIso8601String()]);
        return ['success' => true, 'sessionId' => $id, 'opponents' => $opponents, 'replayed' => false];
    }
    public function finish(Account $actor, Tenant $tenant, string $id, array $choices): array
    {
        app(MobilePenaltyAccess::class)->authorize($actor, $tenant);
        $generation = $this->generation($tenant, true);
        $row = $this->table($tenant, 'sessions')->where('account_id', $actor->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row && (int) $row->generation === $generation, 404);
        $opponents = json_decode(Crypt::decryptString($row->opponents_encrypted), true, 32, JSON_THROW_ON_ERROR);
        $score = MobilePenaltyRules::score($row->mode, $opponents, $choices); $hash = hash('sha256', json_encode($choices, JSON_THROW_ON_ERROR));
        if ($row->finished_at) {
            abort_unless(hash_equals($row->choices_sha256, $hash), 409, 'Diese Runde wurde bereits gewertet.');
            return ['success' => true, 'score' => (int) $row->score, 'replayed' => true];
        }
        $now = Time::now();
        abort_if(Time::parse($row->expires_at)->lessThanOrEqualTo($now) || Time::parse($row->created_at)->diffInSeconds($now, false) < 8, 409, 'Runde abgelaufen oder unvollstaendig.');
        $this->table($tenant, 'sessions')->where('id', $id)->update(['choices_sha256' => $hash, 'score' => $score, 'finished_at' => $now->toIso8601String()]);
        $best = $this->table($tenant, 'best')->where('account_id', $actor->id)->where('mode', $row->mode)->lockForUpdate()->first();
        $member = app(MemberAccessService::class)->represented($actor, $tenant, true)->orderBy('id')->first(['first_name','last_name']);
        $name = $member && trim($member->first_name) !== '' ? trim($member->first_name).(trim($member->last_name) === '' ? '' : ' '.mb_substr(trim($member->last_name), 0, 1).'.') : 'Mitglied';
        if (! $best) $this->table($tenant, 'best')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'mode' => $row->mode,
            'display_name' => mb_substr($name, 0, 100), 'score' => $score, 'achieved_at' => $now->toIso8601String()]);
        elseif ($score > (int) $best->score) $this->table($tenant, 'best')->where('account_id', $actor->id)->where('mode', $row->mode)
            ->update(['display_name' => mb_substr($name, 0, 100), 'score' => $score, 'achieved_at' => $now->toIso8601String()]);
        return ['success' => true, 'score' => $score, 'replayed' => false];
    }
    public function leaderboard(Account $actor, Tenant $tenant, string $mode, int $start, int $limit): array
    {
        app(MobilePenaltyAccess::class)->authorize($actor, $tenant);
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $mode, $start, $limit) {
            Tenant::whereKey($tenant->id)->sharedLock()->firstOrFail();
            $query = $this->table($tenant, 'best')->where('mode', $mode);
            $total = (clone $query)->count();
            $rows = $query->selectRaw('DENSE_RANK() OVER (ORDER BY score DESC) AS "Rank", display_name AS "DisplayName", score AS "Score", CASE WHEN account_id=? THEN 1 ELSE 0 END AS "IsMe"', [$actor->id])
                ->orderByDesc('score')->orderBy('achieved_at')->orderBy('account_id')->offset($start)->limit($limit)->get();
            return ['success' => true, 'entries' => $rows, 'challenge' => $this->generation($tenant, false),
                'canReset' => app(MobilePenaltyAccess::class)->canReset($actor, $tenant), 'totalRecordCount' => $total];
        });
    }
    public function reset(Account $actor, Tenant $tenant, int $shown, string $title, string $body): array
    {
        abort_unless(app(MobilePenaltyAccess::class)->canReset($actor, $tenant), 403);
        $generation = $this->generation($tenant, true);
        abort_unless($shown === $generation && $generation < 2147483646, 409, 'Challenge wurde bereits geaendert. Bitte neu laden.');
        // Durable encrypted source and generation change share the same transaction; no provider call.
        $reset = $this->table($tenant, 'resets')->insertGetId(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
            'previous_generation' => $generation, 'generation' => $generation + 1,
            'title_encrypted' => Crypt::encryptString(trim($title)), 'body_encrypted' => Crypt::encryptString(trim($body)), 'dispatch_reference' => (string) Str::uuid()]);
        $this->table($tenant, 'best')->delete(); $this->table($tenant, 'sessions')->delete();
        $this->table($tenant, 'challenges')->update(['generation' => $generation + 1, 'reset_by' => $actor->id, 'reset_at' => Time::now()->toIso8601String()]);
        return ['success' => true, 'challenge' => $generation + 1, 'resetId' => $reset, 'pushStatus' => 'queued'];
    }
}
