<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Mail};
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

final class CampaignMailQueue
{
    public function enabled(): bool
    {
        return app()->environment('staging') && config('app.url') === 'https://stage.clubunio.com'
            && config('clubunio-campaign-mail.enabled') && config('clubunio-integrations.test_mail_enabled');
    }

    private function table()
    {
        return DB::connection('clubunio')->table('clubunio.campaign_recipients');
    }

    private function address(Tenant $tenant, object $row): ?string
    {
        $email = strtolower(trim((string) app(MemberCampaignService::class)->eligible($tenant)->find($row->member_id)?->email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! str_ends_with($email, '@example.invalid')) return null;
        if (app(MemberAccessService::class)->activeMembers($tenant)->whereRaw('lower(trim(email)) = ?', [$email])->count() !== 1) return null;

        return hash_hmac('sha256', $email, config('app.key'));
    }

    public function enqueue(Account $actor, Tenant $tenant, int $campaign, int $recipient): void
    {
        $service = app(MemberCampaignService::class);
        abort_unless($this->enabled() && $service->allowed($actor, $tenant), 403);
        $service->openCampaign($tenant, $campaign);
        $query = $service->recipients($tenant, $campaign)->where('id', $recipient)->whereIn('channel', ['email', 'both'])
            ->whereNotNull('token_encrypted')->where('mail_state', 'pending');
        $row = (clone $query)->firstOrFail();
        $hash = $this->address($tenant, $row);
        abort_unless($hash && ! $this->suppressed($tenant, $hash), 422);
        abort_unless($query->update(['mail_state' => 'queued', 'mail_due_at' => now(), 'mail_address_hash' => $hash]) === 1, 409);
    }

    private function suppressed(Tenant $tenant, string $hash): bool
    {
        return $this->table()->where('tenant_id', $tenant->id)->where('mail_address_hash', $hash)->where('mail_state', 'bounced')->exists();
    }

    public function bounce(Account $actor, Tenant $tenant, int $campaign, int $recipient): void
    {
        $service = app(MemberCampaignService::class);
        abort_unless($this->enabled() && $service->isPilot($tenant) && $service->allowed($actor, $tenant), 403);
        abort_unless($service->recipients($tenant, $campaign)->where('id', $recipient)->where('mail_state', 'accepted')
            ->whereNotNull('mail_address_hash')->update(['mail_state' => 'bounced']) === 1, 409);
    }

    public static function outcome(?int $code, int $attempt, int $limit): string
    {
        return match (true) {
            $code !== null && $code >= 400 && $code < 500 => $attempt < $limit ? 'queued' : 'failed',
            $code !== null && $code >= 500 && $code < 600 => 'failed',
            default => 'attention',
        };
    }

    public function runOne(?int $onlyRecipient = null): bool
    {
        if (! $this->enabled()) return false;
        $tenant = Tenant::where('slug', 'stage-mobile-pilot')->first();
        if (! $tenant) return false;
        $db = DB::connection('clubunio');
        $row = $db->transaction(function () use ($db, $tenant, $onlyRecipient) {
            $db->selectOne('select pg_advisory_xact_lock(67162310)');
            $rows = $this->table()->where('tenant_id', $tenant->id);
            (clone $rows)->where('mail_state', 'sending')->where('mail_attempted_at', '<', now()->subMinutes(5))
                ->update(['mail_state' => 'attention']);
            $interval = (int) ceil(60 / max(1, min(30, config('clubunio-campaign-mail.per_minute'))));
            if ((clone $rows)->where(fn ($q) => $q->where('mail_state', 'sending')
                ->orWhere('mail_attempted_at', '>', now()->subSeconds($interval)))->exists()) return null;
            if ((clone $rows)->where('mail_code', 421)->where('mail_attempted_at', '>', now()->subMinutes(10))->exists()) return null;
            $row = (clone $rows)->where('mail_state', 'queued')->where('mail_due_at', '<=', now())
                ->when($onlyRecipient !== null, fn ($q) => $q->where('id', $onlyRecipient))
                ->orderBy('mail_due_at')->orderBy('id')->lockForUpdate()->first();
            if ($row) (clone $rows)->where('id', $row->id)->update([
                'mail_state' => 'sending', 'mail_attempted_at' => now(), 'mail_attempts' => $row->mail_attempts + 1,
            ]);

            return $row;
        });
        if (! $row) return false;

        $state = 'attention';
        $code = null;
        $attempt = $row->mail_attempts + 1;
        try {
            $service = app(MemberCampaignService::class);
            $service->openCampaign($tenant, $row->campaign_id);
            if (! $row->token_encrypted || $this->address($tenant, $row) !== $row->mail_address_hash || $this->suppressed($tenant, $row->mail_address_hash)) {
                $state = 'failed';
            } else {
                $sent = Mail::mailer('clubunio_test')->send(
                    ['raw' => __('Bitte aktualisiere deine Kontaktdaten für :club. Die Geschäftsstelle prüft deine Änderungen.', ['club' => $tenant->name])."\n\n".$service->url($tenant, $row)],
                    ['clubunio_reply_to_tenant_id' => (int) $tenant->id],
                    fn ($message) => $message->from(config('clubunio-integrations.from'))->to(TestMailGuard::RECIPIENT)->subject(__('campaign.update')),
                );
                $state = $sent ? 'accepted' : 'attention';
            }
        } catch (UnexpectedResponseException $error) {
            $code = (int) $error->getCode();
            $state = self::outcome($code, $attempt, max(1, min(5, config('clubunio-campaign-mail.attempts'))));
        } catch (\Throwable) {
            // An uncertain SMTP outcome is never retried automatically.
        } finally {
            $this->table()->where('tenant_id', $tenant->id)->where('id', $row->id)->where('mail_state', 'sending')->update([
                'mail_state' => $state, 'mail_code' => $code,
                'mail_due_at' => $state === 'queued' ? now()->addMinutes(10 * $attempt) : null,
            ]);
        }

        return true;
    }
}
