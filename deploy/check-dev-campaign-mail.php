<?php

use App\ClubUnio\Models\{Account, Member, Tenant};
use App\ClubUnio\Services\{CampaignMailQueue, MemberCampaignService};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = DB::connection('clubunio');
if (PHP_SAPI !== 'cli' || ! MemberCampaignService::devTestMode()
    || $db->selectOne('select current_database() as name')->name !== 'clubunio_dev') exit(1);
$db->beginTransaction();
$exit = 0;
try {
    $db->selectOne('select pg_advisory_xact_lock(67162310)');
    $tenant = Tenant::where('slug', 'sv-sissach')->sole();
    $suffix = bin2hex(random_bytes(10));
    $actor = new Account;
    $actor->forceFill(['username' => "campaign-test-admin-$suffix@example.invalid",
        'display_name' => 'Synthetic admin', 'active' => true, 'password' => bcrypt($suffix)])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
    $member = new Member;
    $member->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Campaign',
        'birth_date' => '1985-01-01', 'email' => "blocked-$suffix@example.invalid",
        'member_number' => 1 + (int) $db->table('clubunio.members')->where('tenant_id', $tenant->id)->max('member_number')])->save();
    $service = app(MemberCampaignService::class);
    $id = $service->create($actor, $tenant, 'DEV synthetic campaign', 'email', false);
    $service->recipient($actor, $tenant, $id, $member->id, 'email');
    try {
        $service->release($actor, $tenant, $id);
        throw new RuntimeException('Release accepted a non-test address.');
    } catch (ValidationException) {
    }
    if ($service->recipients($tenant, $id)->whereNotNull('token_encrypted')->exists()) {
        throw new RuntimeException('Rejected release left a token.');
    }
    $member->forceFill(['email' => "campaign-test-$suffix@example.invalid"])->save();
    $service->saveContent($actor, $tenant, $id, 'acknowledgement', []);
    $service->release($actor, $tenant, $id);
    $row = $service->recipients($tenant, $id)->firstOrFail();
    if ($row->mail_state !== 'queued' || ! app(CampaignMailQueue::class)->runOne($row->id)
        || $service->recipients($tenant, $id)->value('mail_state') !== 'accepted') {
        throw new RuntimeException('Synthetic queue delivery failed.');
    }
    $service->acknowledge($tenant, \Illuminate\Support\Facades\Crypt::decryptString($row->token_encrypted));
    if (! $service->recipients($tenant, $id)->whereNotNull('acknowledged_at')->whereNull('token_hash')->exists()) {
        throw new RuntimeException('Test acknowledgement failed.');
    }
    echo "DEV release, blocking non-test addresses, memory transport and acknowledgement verified. No SMTP.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    $exit = 1;
} finally {
    $db->rollBack();
}
exit($exit);
