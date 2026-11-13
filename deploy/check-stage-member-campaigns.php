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
    $campaigns->release($actor, $tenant, $id);
    $row = $campaigns->recipients($tenant, $id)->firstOrFail();
    $token = Crypt::decryptString($row->token_encrypted);
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
    echo "Synthetic campaign, private photo and office approval passed. No email sent.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).' at '.basename($e->getFile()).':'.$e->getLine()."\n");
    $exit = 1;
} finally {
    if ($file) unlink($file);
    $db->rollBack();
}
exit($exit);
