<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\ExpenseService;
use Illuminate\Support\Facades\{Crypt, DB, RateLimiter, Validator};
use Illuminate\Support\Str;

final class MobileExpenseOcr
{
    private const OPERATION = 'expense_ocr';
    private const PROVIDER = 'azure_document_intelligence';
    public function available(Tenant $tenant): bool {
        try { $this->configuration($tenant); return true; } catch (\Throwable) { return false; }
    }
    private function configuration(Tenant $tenant): array {
        abort_unless(config('clubunio-expense-ocr.enabled') && config('clubunio-expense-ocr.cost_reviewed')
            && in_array($tenant->slug, config('clubunio-expense-ocr.tenant_slugs', []), true), 503, 'Beleganalyse ist noch nicht freigeschaltet.');
        try { $origin = app(AzureExpenseOcrProvider::class)->origin(); }
        catch (\Throwable) { abort(503, 'Beleganalyse ist noch nicht konfiguriert.'); }
        $reserve = (int) config('clubunio-expense-ocr.reservation_microusd');
        $limit = min((int) config('clubunio-expense-ocr.monthly_limit_microusd'), (int) config('clubunio-mobile.ai.monthly_limit_microusd', 20_000_000));
        abort_unless($reserve > 0 && $limit >= $reserve, 503, 'Kostenlimit fuer Beleganalyse fehlt.');
        return ['reserve' => $reserve, 'limit' => $limit,
            'fingerprint' => hash('sha256', json_encode([$origin, hash('sha256', (string) config('clubunio-expense-ocr.key')), AzureExpenseOcrProvider::MODEL,
                AzureExpenseOcrProvider::VERSION, $reserve, (float) config('clubunio-expense-ocr.min_confidence', 0.75), 'expense-ocr-v1'], JSON_THROW_ON_ERROR))];
    }
    private function table(Tenant $tenant) { return DB::connection('clubunio')->table('clubora.mobile_ai_work')->where('tenant_id', $tenant->id); }
    private function source(Account $actor, Tenant $tenant, string $id, int $revision): array {
        $request = request();
        abort_unless($request->attributes->has('mobile.session'), 401);
        $fresh = app(MobileSessions::class)->authenticate($request);
        abort_unless((int) $fresh->id === (int) $actor->id, 401);
        app(MobileAccess::class)->enter($fresh, $tenant);
        abort_unless(app(ExpenseService::class)->portalAllowed($fresh, $tenant), 403);
        $row = app(MobileExpenseReceipts::class)->owned($fresh, $tenant, $id);
        abort_unless($row->state === 'clean' && $row->bytes_encrypted && (int) $row->revision === $revision
            && now()->isBefore($row->expires_at) && $row->scanned_at && $row->scanner_version !== '', 409, 'Belegentwurf ist nicht mehr aktuell.');
        $maximum = min(5242880, (int) config('clubunio-expense-ocr.max_bytes', 4194304));
        abort_unless($maximum > 0 && (int) $row->byte_count > 0 && (int) $row->byte_count <= $maximum
            && strlen($row->bytes_encrypted) <= 10 * 1024 * 1024 && in_array($row->mime_type, ['application/pdf','image/jpeg','image/png'], true), 422, 'Beleg ist fuer die Analyse zu gross oder ungeeignet.');
        $bytes = Crypt::decryptString($row->bytes_encrypted);
        abort_unless(strlen($bytes) === (int) $row->byte_count && hash_equals($row->sha256, hash('sha256', $bytes)), 409, 'Belegpruefsumme stimmt nicht ueberein.');
        return [$row, $bytes];
    }
    private function lockSession(Tenant $tenant): void {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', request()->attributes->get('mobile.session')->id)->sharedLock()->firstOrFail();
    }
    public function execute(Account $actor, Tenant $tenant, string $receipt, array $input): array {
        $d = Validator::make($input, ['request_key' => 'required|uuid', 'draft_revision' => 'required|integer|min:1', 'consent' => 'required|accepted'])->validate();
        [$draft, $bytes] = $this->source($actor, $tenant, $receipt, (int) $d['draft_revision']);
        $config = $this->configuration($tenant);
        $fingerprint = hash('sha256', json_encode([$draft->id, $draft->sha256, (int) $draft->revision, $draft->scanner_version, $draft->scanned_at, $config['fingerprint']], JSON_THROW_ON_ERROR));
        $rate = 'expense-ocr:'.$tenant->id.':'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rate, 20), 429, 'Bitte die laufende Beleganalyse abwarten.'); RateLimiter::hit($rate, 60);
        $db = DB::connection('clubunio');
        $work = $db->transaction(function () use ($actor, $tenant, $receipt, $d, $fingerprint, $config) {
            $this->lockSession($tenant); $this->source($actor, $tenant, $receipt, (int) $d['draft_revision']);
            $q = $this->table($tenant)->where('account_id', $actor->id);
            $row = (clone $q)->where('request_key', $d['request_key'])->first();
            if ($row) {
                abort_unless($row->operation === self::OPERATION && $row->provider === self::PROVIDER && hash_equals($row->fingerprint, $fingerprint), 409, 'Analyseauftrag passt nicht zu diesem Belegstand.');
                return $row;
            }
            $cached = (clone $q)->where('operation', self::OPERATION)->where('fingerprint', $fingerprint)->where('state', 'succeeded')->first();
            if ($cached) {
                $id = (string) Str::uuid();
                $this->table($tenant)->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'request_key' => $d['request_key'],
                    'fingerprint' => $fingerprint, 'provider' => self::PROVIDER, 'operation' => self::OPERATION, 'revision' => (int) $d['draft_revision'],
                    'state' => 'succeeded', 'reserved_microusd' => 0, 'actual_microusd' => 0, 'model' => $cached->model, 'result_encrypted' => $cached->result_encrypted]);
                return $this->table($tenant)->where('id', $id)->first();
            }
            abort_if((clone $q)->where('operation', self::OPERATION)->where('fingerprint', $fingerprint)->whereIn('state', ['starting','running','polling','attention'])->exists(), 409,
                'Eine Analyse fuer diesen Beleg laeuft bereits oder muss geprueft werden.');
            $daily = max(1, min(100, (int) config('clubunio-expense-ocr.daily_requests_per_account', 10)));
            abort_if((clone $q)->where('operation', self::OPERATION)->where('reserved_microusd', '>', 0)->where('created_at', '>=', now('Europe/Zurich')->startOfDay()->toIso8601String())->count() >= $daily, 429, 'Tageslimit fuer Beleganalyse erreicht.');
            $spent = (int) $this->table($tenant)->where('created_at', '>=', now('Europe/Zurich')->startOfMonth()->toIso8601String())
                ->selectRaw('COALESCE(SUM(COALESCE(actual_microusd,reserved_microusd)),0) AS spent')->value('spent');
            abort_if($spent + $config['reserve'] > $config['limit'], 429, 'Monatslimit fuer KI-Anfragen erreicht.');
            $id = (string) Str::uuid();
            $this->table($tenant)->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'request_key' => $d['request_key'], 'fingerprint' => $fingerprint,
                'provider' => self::PROVIDER, 'operation' => self::OPERATION, 'revision' => (int) $d['draft_revision'], 'state' => 'starting',
                'reserved_microusd' => $config['reserve'], 'model' => AzureExpenseOcrProvider::MODEL.'@'.AzureExpenseOcrProvider::VERSION]);
            return (object) [...(array) $this->table($tenant)->where('id', $id)->first(), 'new_work' => true];
        });
        if ($work->state === 'succeeded') return $this->cached($actor, $tenant, $receipt, (int) $d['draft_revision'], $work);
        if ($work->state === 'starting') {
            abort_unless($work->new_work ?? false, 409, 'Analysebeginn ungewiss; es erfolgt kein zweiter kostenpflichtiger Aufruf.');
            try {
                $job = app(AzureExpenseOcrProvider::class)->start($bytes, $work->id);
                $db->transaction(function () use ($actor, $tenant, $receipt, $d, $work, $job) {
                    $this->lockSession($tenant); $this->source($actor, $tenant, $receipt, (int) $d['draft_revision']);
                    $this->table($tenant)->where('id', $work->id)->where('state', 'starting')->update(['state' => 'running', 'result_encrypted' => Crypt::encryptString(json_encode(['job' => $job, 'poll_after' => 0], JSON_THROW_ON_ERROR))]);
                });
            } catch (\Throwable) {
                $this->table($tenant)->where('id', $work->id)->where('state', 'starting')->update(['state' => 'attention']);
                abort(502, 'Analysebeginn konnte nicht bestaetigt werden. Bitte den Auftrag pruefen; keine automatische Wiederholung.');
            }
        }
        return $this->poll($actor, $tenant, $receipt, (int) $d['draft_revision'], $work->id);
    }
    private function cached(Account $actor, Tenant $tenant, string $receipt, int $revision, object $row): array {
        $this->source($actor, $tenant, $receipt, $revision);
        $value = json_decode(Crypt::decryptString($row->result_encrypted), true, 64, JSON_THROW_ON_ERROR);
        abort_unless(is_array($value) && isset($value['suggestion'], $value['warnings'], $value['meta']), 502);
        return $this->result($row, $value, true, $receipt, $revision);
    }
    private function result(object $work, array $value, bool $cached, string $receipt, int $revision): array {
        $source = $value['_source'] ?? null; unset($value['_source']);
        $result = ['state' => 'succeeded', 'cached' => $cached] + $value;
        if (config('clubunio-expense-provenance.enabled', false) && is_array($source)
            && ($source['draft_id'] ?? null) === $receipt && ($source['draft_revision'] ?? null) === $revision
            && is_string($source['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $source['sha256'])) {
            $result['provenance'] = ['work_id' => $work->id, 'draft_id' => $receipt, 'draft_revision' => $revision, 'source_sha256' => $source['sha256']];
        }
        return $result;
    }
    private function pending(): array { return ['state' => 'pending', 'retry_after' => max(1, min(30, (int) config('clubunio-expense-ocr.poll_interval_seconds', 3)))]; }
    private function poll(Account $actor, Tenant $tenant, string $receipt, int $revision, string $id): array {
        $db = DB::connection('clubunio');
        $claim = $db->transaction(function () use ($actor, $tenant, $receipt, $revision, $id) {
            $this->lockSession($tenant); $this->source($actor, $tenant, $receipt, $revision);
            $row = $this->table($tenant)->where('account_id', $actor->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row->state === 'succeeded') return ['cached' => $row];
            abort_unless(in_array($row->state, ['running','polling'], true) && $row->result_encrypted, 409, 'Diese Beleganalyse muss geprueft werden.');
            $data = json_decode(Crypt::decryptString($row->result_encrypted), true, 8, JSON_THROW_ON_ERROR);
            abort_unless(is_array($data) && is_string($data['job'] ?? null), 502);
            if (($data['poll_after'] ?? 0) > now()->getTimestamp() || ($row->state === 'polling' && ($data['lease_until'] ?? 0) > now()->getTimestamp())) return ['pending' => true];
            $lease = (string) Str::uuid(); $data['lease'] = $lease; $data['lease_until'] = now()->addSeconds(25)->getTimestamp();
            $this->table($tenant)->where('id', $id)->update(['state' => 'polling', 'result_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))]);
            return ['job' => $data['job'], 'lease' => $lease];
        });
        if (isset($claim['cached'])) return $this->cached($actor, $tenant, $receipt, $revision, $claim['cached']);
        if (isset($claim['pending'])) return $this->pending();
        try { $result = app(AzureExpenseOcrProvider::class)->result($claim['job']); }
        catch (\Throwable) { $result = ['status' => 'running']; } // GET retries never submit a second charged analysis.
        return $db->transaction(function () use ($actor, $tenant, $receipt, $revision, $id, $claim, $result) {
            $this->lockSession($tenant);
            $row = $this->table($tenant)->where('account_id', $actor->id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $pointer = $row->result_encrypted ? json_decode(Crypt::decryptString($row->result_encrypted), true, 8, JSON_THROW_ON_ERROR) : [];
            if ($row->state !== 'polling' || ($pointer['lease'] ?? null) !== $claim['lease']) return $this->pending();
            try { [$sourceDraft] = $this->source($actor, $tenant, $receipt, $revision); }
            catch (\Throwable) { $this->table($tenant)->where('id', $id)->update(['state' => 'attention']); return ['state' => 'stale']; }
            if (in_array($result['status'], ['notStarted','running'], true)) {
                $this->table($tenant)->where('id', $id)->update(['state' => 'running', 'result_encrypted' => Crypt::encryptString(json_encode(['job' => $claim['job'],
                    'poll_after' => now()->addSeconds($this->pending()['retry_after'])->getTimestamp()], JSON_THROW_ON_ERROR))]);
                return $this->pending();
            }
            if ($result['status'] !== 'succeeded') { $this->table($tenant)->where('id', $id)->update(['state' => 'attention']); return ['state' => 'failed']; }
            try { $suggestion = app(AzureExpenseOcrProvider::class)->suggestion($result); }
            catch (\Throwable) { $this->table($tenant)->where('id', $id)->update(['state' => 'attention']); return ['state' => 'failed']; }
            $suggestion['_source'] = ['draft_id' => $receipt, 'draft_revision' => $revision, 'sha256' => $sourceDraft->sha256,
                'scanner_version' => $sourceDraft->scanner_version, 'scanned_at' => \Carbon\CarbonImmutable::parse($sourceDraft->scanned_at)->utc()->toIso8601String()];
            $this->table($tenant)->where('id', $id)->update(['state' => 'succeeded', 'result_encrypted' => Crypt::encryptString(json_encode($suggestion, JSON_THROW_ON_ERROR))]);
            return $this->result($row, $suggestion, false, $receipt, $revision);
        });
    }
}
