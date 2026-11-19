<?php

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{MemberChangeService, MemberService, StageMigrationTarget};
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Illuminate\Validation\ValidationException;

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com'
    || $db->selectOne('select current_database() as name')->name !== 'clubunio_stage') exit(1);
StageMigrationTarget::authorize($db, 'clubunio_stage');
Http::preventStrayRequests();
Mail::fake();
$db->beginTransaction();
$exit = 0;
try {
    $tenant = Tenant::where('slug', 'stage-mobile-pilot')->sole();
    Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
    $suffix = bin2hex(random_bytes(10));
    $account = static function (string $role) use ($tenant, $suffix, $db): Account {
        $actor = new Account;
        $actor->forceFill(['username' => "conflict-$role-$suffix@example.invalid", 'display_name' => 'Synthetic conflict test',
            'password' => bcrypt($suffix), 'active' => true])->save();
        $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
            'role' => $role, 'portal_enabled' => true]);
        return $actor;
    };
    $admin = $account('admin');
    $requester = $account('member');
    $service = app(MemberChangeService::class);
    $require = static function (bool $ok, string $step): void {
        if (! $ok) throw new RuntimeException($step);
    };
    $status = $db->table('clubunio.member_change_statuses')->where('tenant_id', $tenant->id)->where('code', 'pending')->sole();
    $number = 1 + (int) $db->table('clubunio.members')->where('tenant_id', $tenant->id)->max('member_number');
    $fixture = static function (int $number, array $changes) use ($tenant, $requester, $service, $status, $db): array {
        $member = new Member;
        $member->forceFill(['tenant_id' => $tenant->id, 'member_number' => $number,
            'first_name' => 'Synthetic', 'last_name' => 'Conflict', 'birth_date' => '1985-01-01',
            'city' => 'Original', 'phone_private' => '123'])->save();
        // The requester is linked to one fixture at a time.
        $db->table('clubunio.account_member_links')->where('tenant_id', $tenant->id)->where('account_id', $requester->id)->delete();
        $db->table('clubunio.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $requester->id,
            'member_id' => $member->id, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic']);
        $request = new MemberChangeRequest;
        $request->forceFill(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'requested_by' => $requester->id,
            'state' => 'pending', 'status_id' => $status->id, 'revision' => 1, 'member_version' => MemberService::version($member),
            'snapshot_encrypted' => Crypt::encryptString(json_encode(['before' => array_intersect_key($service->values($member), $changes),
                'changes' => $changes, 'team_id' => null], JSON_THROW_ON_ERROR))])->save();
        return [$member, $request];
    };
    [$member, $request] = $fixture($number++, ['city' => 'Requested']);
    $member->forceFill(['email' => 'independent@example.invalid'])->save();
    $service->review($admin, $tenant, $request->id, 1, true, null);
    $require($member->fresh()->city === 'Requested' && $member->fresh()->email === 'independent@example.invalid'
        && $request->fresh()->state === 'approved', 'unrelated change preserved');

    [$member, $request] = $fixture($number++, ['phone_private' => null]);
    $member->forceFill(['phone_private' => null])->save();
    $auditBefore = $db->table('clubunio.object_changes')->where('tenant_id', $tenant->id)->where('object_type', 'members')->where('object_id', $member->id)->count();
    $updated = (string) $member->fresh()->updated_at;
    $service->review($admin, $tenant, $request->id, 1, true, null);
    $require($request->fresh()->state === 'approved' && (string) $member->fresh()->updated_at === $updated
        && $auditBefore === $db->table('clubunio.object_changes')->where('tenant_id', $tenant->id)->where('object_type', 'members')->where('object_id', $member->id)->count(),
        'already applied: complete without member write');

    [$member, $request] = $fixture($number++, ['city' => 'Requested', 'phone_private' => null]);
    $member->forceFill(['city' => 'Third party'])->save();
    try {
        $service->review($admin, $tenant, $request->id, 1, true, null);
        throw new RuntimeException('real conflict accepted');
    } catch (ValidationException) {
    }
    $require($request->fresh()->state === 'pending' && $member->fresh()->phone_private === '123', 'conflict atomicity');

    [$member, $request] = $fixture($number++, ['city' => 'Requested', 'phone_private' => null]);
    $member->forceFill(['city' => 'Requested'])->save();
    $service->review($admin, $tenant, $request->id, 1, true, null);
    $require($request->fresh()->state === 'approved' && $member->fresh()->phone_private === null, 'partially applied request completed');
    try {
        $service->review($admin, $tenant, $request->id, 1, true, null);
        throw new RuntimeException('completed request replay accepted');
    } catch (ValidationException) {
    }

    [$member, $request] = $fixture($number++, ['city' => 'Requested']);
    $db->table('clubunio.account_member_links')->where('account_id', $requester->id)->where('tenant_id', $tenant->id)->update(['state' => 'revoked']);
    try {
        $service->review($admin, $tenant, $request->id, 1, true, null);
        throw new RuntimeException('revoked requester accepted');
    } catch (ValidationException) {
    }
    $require($request->fresh()->state === 'pending', 'revoked link remains blocked');
    [$member, $request] = $fixture($number++, ['city' => 'Requested']);
    try {
        $service->review($requester, $tenant, $request->id, 1, true, null);
        throw new RuntimeException('unauthorised approver accepted');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        $require($error->getStatusCode() === 403, 'approver permission enforced');
    }
    $member->forceFill(['exit_date' => now()->subDay()->toDateString()])->save();
    try {
        $service->review($admin, $tenant, $request->id, 1, true, null);
        throw new RuntimeException('departed member accepted');
    } catch (ValidationException) {
    }
    $require($request->fresh()->state === 'pending', 'departed member remains blocked');
    $require(Http::recorded()->isEmpty(), 'no external HTTP requests');
    echo "Stage field conflicts, no-op approval, atomicity and requester revocation verified; all fixtures rolled back.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    if (get_class($error) === RuntimeException::class) fwrite(STDERR, $error->getMessage()."\n");
    $exit = 1;
} finally {
    $db->rollBack();
}
exit($exit);
