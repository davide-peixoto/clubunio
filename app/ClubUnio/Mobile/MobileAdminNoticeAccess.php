<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MobileAdminNoticeAccess
{
    public const ROLES = ['admin' => 'Admin', 'office' => 'Geschäftsstelle', 'technical_lead' => 'Technische Leitung', 'trainer' => 'Trainer', 'member' => 'Mitglied'];

    public function administrator(Account $actor, Tenant $tenant): bool
    {
        try { app(MobileAccess::class)->enter($actor, $tenant); }
        catch (HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; return false; }
        return in_array(app(MemberAccessService::class)->membership($actor, $tenant)?->role, ['admin', 'office'], true);
    }

    public function authorize(Account $actor, Tenant $tenant): void
    {
        abort_unless(config('clubunio-mobile.native_push_admin_enabled', false), 503, 'Persönliche Pushverwaltung noch nicht freigeschaltet.');
        abort_unless($this->administrator($actor, $tenant), 403);
    }

    public function matches(Account $actor, Tenant $tenant, string $type, ?string $value): bool
    {
        try { app(MobileAccess::class)->enter($actor, $tenant); }
        catch (HttpException $error) { if ($error->getStatusCode() !== 403) throw $error; return false; }
        $role = app(MemberAccessService::class)->membership($actor, $tenant)?->role;
        return match ($type) {
            'all' => true,
            'office' => $role === 'office',
            'trainer_office' => in_array($role, ['trainer', 'office'], true),
            'account' => (string) $actor->id === $value,
            'role' => $role === $value && array_key_exists($role, self::ROLES),
            'member' => app(MemberAccessService::class)->represented($actor, $tenant)->whereKey((int) $value)->exists(),
            default => false,
        };
    }

    /** Only an original snapshot recipient who still qualifies can read this source. */
    public function notice(Account $actor, Tenant $tenant, int $id): object
    {
        abort_unless(config('clubunio-mobile.native_push_admin_enabled', false), 404);
        $db = DB::connection('clubunio');
        $notice = $db->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($notice && $db->table('clubora.mobile_admin_notice_recipients')->where('tenant_id', $tenant->id)
            ->where('notice_id', $id)->where('account_id', $actor->id)->exists(), 404);
        abort_unless($this->matches($actor, $tenant, $notice->target_type, $notice->target_value), 404);
        return $notice;
    }

    /** Pending device delivery also rechecks the actual sender's current administrative role. */
    public function publicationCurrent(Tenant $tenant, int $id): bool
    {
        if (! config('clubunio-mobile.native_push_admin_enabled', false)) return false;
        $notice = DB::connection('clubunio')->table('clubora.mobile_admin_notices')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        $sender = $notice ? Account::whereKey($notice->account_id)->first() : null;
        return $sender && $this->administrator($sender, $tenant);
    }
}
