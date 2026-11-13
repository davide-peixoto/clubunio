<?php

use App\ClubUnio\Models\{Account, Member, Tenant};
use App\ClubUnio\Services\{MemberCampaignService, MemberChangeService, MemberService, StageMigrationTarget};
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Crypt, DB, Mail};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('app.url') !== 'https://stage.clubunio.com') {
    exit(1);
}
$db = DB::connection('clubunio');
StageMigrationTarget::authorize($db, 'clubunio_stage');
Mail::fake();
config(['clubunio-profile-photos.enabled' => true]);
$require = static function (bool $condition, string $step): void {
    if (! $condition) throw new RuntimeException($step);
};
$db->beginTransaction();
$file = null;
$exit = 0;
try {
    $tenant = Tenant::where('slug', 'stage-mobile-pilot')->sole();
    $suffix = bin2hex(random_bytes(8));
    $actor = new Account;
    $actor->forceFill(['username' => "campaign-$suffix@example.invalid", 'display_name' => 'Synthetic Office',
        'active' => true, 'password' => bcrypt(bin2hex(random_bytes(24)))])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
    $member = new Member;
    $member->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Campaign',
        'birth_date' => '1985-01-01', 'email' => "member-$suffix@example.invalid", 'city' => 'Original',
        'street' => 'Synthetic street', 'postal_code' => '4000',
        'member_number' => 1 + (int) $db->table('clubunio.members')->where('tenant_id', $tenant->id)->max('member_number')])->save();
    $campaigns = app(MemberCampaignService::class);
    $changes = app(MemberChangeService::class);
    foreach (['pending', 'approved', 'rejected', 'withdrawn'] as $status) {
        $db->table('clubunio.member_change_statuses')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'code' => $status, 'name' => $status, 'color' => 'gray',
        ]);
    }
    $file = tempnam(sys_get_temp_dir(), 'campaign-photo-');
    imagejpeg(imagecreatetruecolor(8, 8), $file);
    $photos = app(\App\ClubUnio\Services\ProfilePhotoService::class);
    $id = $campaigns->create($actor, $tenant, 'Synthetic campaign', 'email', false);
    $campaigns->recipient($actor, $tenant, $id, $member->id, 'email');
    $campaigns->recipient($actor, $tenant, $id, $member->id, null);
    $require(! $campaigns->recipients($tenant, $id)->exists(), 'recipient removal');
    $campaigns->recipient($actor, $tenant, $id, $member->id, 'both');
    $campaigns->saveContent($actor, $tenant, $id, 'member_update', ['de' => ['subject' => 'Synthetic subject'], 'en' => ['subject' => 'Synthetic subject']]);
    $testQueue = in_array('--queue', $argv, true);
    config(['clubunio-campaign-mail.enabled' => $testQueue, 'clubunio-integrations.test_mail_enabled' => $testQueue]);
    $campaigns->release($actor, $tenant, $id);
    $row = $campaigns->recipients($tenant, $id)->firstOrFail();
    if ($testQueue) {
        $queue = app(\App\ClubUnio\Services\CampaignMailQueue::class);
        $require($campaigns->recipients($tenant, $id)->value('mail_state') === 'queued', 'queue persisted');
        config(['mail.mailers.clubunio_test' => ['transport' => 'array'], 'clubunio-integrations.from' => 'sender@example.invalid']);
        $manager = new \Illuminate\Mail\MailManager($app);
        Mail::swap($manager);
        $require($queue->runOne($row->id), 'worker claimed queued test');
        $messages = $manager->mailer('clubunio_test')->getSymfonyTransport()->messages();
        $require(count($messages) === 1 && $messages[0]->getEnvelope()->getRecipients()[0]->getAddress()
            === \App\ClubUnio\Services\TestMailGuard::RECIPIENT, 'only fixed test recipient');
        $require($messages[0]->getOriginalMessage()->getSubject() === 'Synthetic subject', 'configured subject');
        $require(!$queue->runOne($row->id), 'no duplicate dispatch');
        $queue->bounce($actor, $tenant, $id, $row->id);
        $require($campaigns->recipients($tenant, $id)->value('mail_state') === 'bounced', 'bounce persisted');
    }
    $token = Crypt::decryptString($row->token_encrypted);
    $http = \Illuminate\Http\Request::create(config('app.url'));
    $http->setUserResolver(fn () => $actor);
    $pdf = app(\App\ClubUnio\Http\MemberCampaignController::class)->letters($http, $tenant, $id, $campaigns);
    $require(str_starts_with($pdf->getContent(), '%PDF-'), 'combined PDF output');
    $qr = (new chillerlan\QRCode\QRCode(new chillerlan\QRCode\QROptions([
        'outputInterface' => chillerlan\QRCode\Output\QRMarkupSVG::class, 'outputBase64' => true,
    ])))->render($campaigns->url($tenant, $row));
    $require(str_starts_with($qr, 'data:image/svg+xml;base64,'), 'QR output');
    $campaigns->invitation($tenant, $token);
    $campaigns->invitation($tenant, $token);
    $request = $changes->submitCampaign($tenant, $token, ['city' => 'Reviewed', '_version' => MemberService::version($member->fresh())],
        new UploadedFile($file, 'fixture.jpg', 'image/jpeg', null, true));
    $require((bool) $request->portrait_encrypted && !$photos->memberAvailable($tenant, $member->id), 'portrait pending');
    $require($request->state === 'pending' && $member->fresh()->city === 'Original', 'pending does not write');
    $require(! $campaigns->recipients($tenant, $id)->whereNotNull('token_hash')->exists(), 'one-time token');
    $changes->review($actor, $tenant, $request->id, 1, true, null);
    $require($member->fresh()->city === 'Reviewed', 'office approval applies changes');
    $require($photos->memberAvailable($tenant, $member->id) && !$request->fresh()->portrait_encrypted, 'office publishes portrait');
    $parentEmail = "parent-$suffix@example.invalid";
    $billing = $db->table('clubunio.billing_recipients')->insertGetId([
        'tenant_id' => $tenant->id, 'code' => "test-$suffix", 'name' => 'Synthetic parent', 'email_field' => 'mother_email',
    ]);
    $child = new Member;
    $child->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Minor',
        'birth_date' => now()->subYears(12)->toDateString(), 'mother_email' => $parentEmail, 'billing_recipient_id' => $billing,
        'member_number' => 1 + (int) $member->member_number])->save();
    $parent = new Account;
    $parent->forceFill(['username' => $parentEmail, 'display_name' => 'Synthetic Parent', 'active' => true,
        'password' => bcrypt(bin2hex(random_bytes(24)))])->save();
    $db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $parent->id,
        'role' => 'member', 'portal_enabled' => true]);
    $link = $db->table('clubunio.account_member_links')->insertGetId(['tenant_id' => $tenant->id, 'account_id' => $parent->id,
        'member_id' => $child->id, 'kind' => 'parent', 'state' => 'approved', 'source' => 'synthetic']);
    $ack = $campaigns->create($actor, $tenant, 'Synthetic acknowledgement', 'email', false);
    $campaigns->saveContent($actor, $tenant, $ack, 'acknowledgement', []);
    $campaigns->recipient($actor, $tenant, $ack, $child->id, 'email');
    try {
        $campaigns->release($actor, $tenant, $ack);
        throw new RuntimeException('unverified address accepted');
    } catch (\Illuminate\Validation\ValidationException) {
    }
    $proof = (string) \Illuminate\Support\Str::uuid();
    $hash = app(\App\ClubUnio\Mobile\MobileIdentityChallenges::class)->emailHash($parentEmail);
    $db->table('clubunio.mobile_identity_challenges')->insert(['id' => $proof, 'tenant_id' => $tenant->id, 'purpose' => 'enroll',
        'request_key' => $proof, 'request_fingerprint' => $hash, 'email_hash' => $hash, 'secret_hash' => $hash,
        'account_id' => $parent->id, 'state' => 'completed', 'completed_at' => now(), 'expires_at' => now()->addMinute()]);
    $db->table('clubunio.mobile_account_emails')->insert(['account_id' => $parent->id, 'email_hash' => $hash,
        'email_encrypted' => Crypt::encryptString($parentEmail), 'verified_at' => now(),
        'source' => 'authenticated_enrollment', 'proof_challenge_id' => $proof]);
    $campaigns->release($actor, $tenant, $ack);
    $guardian = $campaigns->recipients($tenant, $ack)->firstOrFail();
    $require((int) $guardian->guardian_account_id === (int) $parent->id, 'verified guardian bound');
    if ($testQueue) $require($guardian->mail_state === 'queued', 'guardian email queued');
    $db->table('clubunio.account_member_links')->where('id', $link)->update(['state' => 'revoked']);
    $require($campaigns->deliveryMember($tenant, $guardian) === null, 'revocation invalidates delivery and token');
    $db->table('clubunio.account_member_links')->where('id', $link)->update(['state' => 'approved']);
    $campaigns->acknowledge($tenant, Crypt::decryptString($guardian->token_encrypted));
    $require($campaigns->recipients($tenant, $ack)->whereNotNull('acknowledged_at')->whereNull('token_hash')->whereNull('request_id')->exists(),
        'one-time acknowledgement without member change');
    echo "Synthetic release, guardian, campaign PDF and office approval passed. No external mail sent.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' at '.basename($e->getFile()).':'.$e->getLine()."\n");
    $exit = 1;
} finally {
    if ($file) unlink($file);
    $db->rollBack();
}
exit($exit);
