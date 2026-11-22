<?php

// Explicit synthetic PostgreSQL fixture. No pass is sent to Apple and all rows roll back.
if (($argv[1] ?? '') !== '--synthetic-rollback' || ! in_array($argv[2] ?? '', ['clubunio_dev', 'clubunio_stage'], true)) {
    throw new RuntimeException('Explicit synthetic target required');
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection('clubunio');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $argv[2]) {
    fwrite(STDERR, "Unexpected database\n");
    exit(1);
}
$expect = static function (bool $condition): void { if (! $condition) throw new RuntimeException('Synthetic card/wallet assertion failed'); };
config(['cache.default' => 'array', 'clubunio-member-cards.verification_enabled' => true,
    'clubunio-member-cards.public_origin' => config('app.url'),
    'clubunio-wallet.enabled' => true, 'clubunio-wallet.web_service_origin' => config('app.url'),
    'clubunio-wallet.pass_type_identifier' => 'pass.invalid.synthetic.membercard',
    'clubunio-wallet.team_identifier' => 'SYNTHETIC1', 'clubunio-mobile.native_member_id_card_enabled' => true]);
$app->instance(App\ClubUnio\Services\SignageBrandService::class, new class extends App\ClubUnio\Services\SignageBrandService {
    public function design(App\ClubUnio\Models\Tenant $tenant): array {
        return ['color' => '#cf1032', 'logo' => '/images/svs-logo.png', 'title' => $tenant->name, 'ink' => '#ffffff'];
    }
});
$app->instance(App\ClubUnio\Wallet\ApplePassBundle::class, new class extends App\ClubUnio\Wallet\ApplePassBundle {
    public function available(): bool { return true; }
    public function signingVersion(): string { return hash('sha256', 'synthetic-no-signing'); }
    public function build(array $pass, array $images): string { throw new RuntimeException('No signing in live fixture'); }
});
$app->instance(App\ClubUnio\Wallet\AppleWalletPush::class, new class extends App\ClubUnio\Wallet\AppleWalletPush {
    public function send(string $token, string $type): int { throw new RuntimeException('No external delivery in live fixture'); }
});
$request = Illuminate\Http\Request::create(config('app.url').'/member-card/'.str_repeat('0', 64), 'GET', [], [], [],
    ['HTTP_X_CLUBUNIO_MOBILE_FEATURES' => 'member-id-card-v1']);
$app->instance('request', $request);
$db->beginTransaction();
try {
    $db->statement("SET LOCAL application_name='member-card-wallet-synthetic-rollback'");
    $tenant = App\ClubUnio\Models\Tenant::create(['slug' => 'wallet-qa-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic Card Club']);
    $other = App\ClubUnio\Models\Tenant::create(['slug' => 'wallet-other-'.bin2hex(random_bytes(6)), 'name' => 'Other Synthetic Club']);
    $status = new App\ClubUnio\Models\MemberStatus;
    $status->forceFill(['tenant_id' => $tenant->id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic eligible status', 'active' => true, 'member_card_eligible' => true])->save();
    $type = new App\ClubUnio\Models\MemberType;
    $type->forceFill(['tenant_id' => $tenant->id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic type', 'active' => true])->save();
    $member = new App\ClubUnio\Models\Member;
    $member->forceFill(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Card Holder',
        'status_id' => $status->id, 'type_id' => $type->id, 'email' => 'PRIVATE@example.invalid', 'birth_date' => '2007-08-06'])->save();
    $actor = new App\ClubUnio\Models\Account;
    $actor->forceFill(['username' => 'wallet-qa-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic wallet check',
        'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 4]), 'active' => true, 'api_only' => false])->save();
    $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
    $dto = app(App\ClubUnio\Mobile\MobileMemberIdCardData::class)->show($actor, $tenant, $member->id);
    $expect($dto['Wallet']['AppleAvailable'] === true && $dto['Brand']['LogoData'] !== null);
    $cards = app(App\ClubUnio\Services\MemberCardService::class);
    $qr = $cards->verification($tenant, $member->id);
    $token = basename(parse_url($qr['Url'], PHP_URL_PATH));
    $expect($cards->resolve($token)['Valid'] === true);
    $expect(! str_contains(json_encode($cards->resolve($token)), 'PRIVATE'));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    foreach (['de', 'en'] as $locale) {
        $web = $kernel->handle(Illuminate\Http\Request::create($qr['Url'].'?lang='.$locale, 'GET'));
        $expect($web->getStatusCode() === 200 && str_contains($web->getContent(), 'Synthetic Card Holder'));
        $expect($web->headers->get('Content-Language') === $locale);
        $expect(str_contains($web->headers->get('Cache-Control'), 'no-store'));
        $expect(! str_contains($web->getContent(), 'PRIVATE'));
    }
    $app->instance('request', $request);
    $version = $cards->snapshot($tenant, $member->id)['SourceVersion'];
    $expect($version === $cards->snapshot($tenant, $member->id)['SourceVersion']);
    $wallet = app(App\ClubUnio\Wallet\MemberWalletService::class);
    $pass = $wallet->issue($tenant, $member->id);
    $expect($pass->tenant_id == $tenant->id && $pass->member_id == $member->id);
    $payload = $wallet->payload($pass);
    $expect($payload['barcodes'][0]['message'] === $qr['Url'] && $payload['voided'] === false);
    $auth = 'ApplePass '.Illuminate\Support\Facades\Crypt::decryptString($pass->auth_encrypted);
    $expect($wallet->authorizedPass($pass->pass_type, $pass->serial, $auth)->serial === $pass->serial);
    $db->table('clubora.member_statuses')->where('id', $status->id)->update(['member_card_eligible' => false]);
    $expect($cards->resolve($token)['Valid'] === false && $cards->resolve($token)['Name'] === null);
    $invalid = $kernel->handle(Illuminate\Http\Request::create($qr['Url'].'?lang=en', 'GET'));
    $expect($invalid->getStatusCode() === 200 && ! str_contains($invalid->getContent(), 'Synthetic Card Holder'));
    $app->instance('request', $request);
    $changed = $wallet->refresh($pass);
    $expect($changed->revision > $pass->revision && $changed->update_tag > $pass->update_tag);
    $expect($wallet->payload($changed)['voided'] === true);
    $db->table('clubora.member_statuses')->where('id', $status->id)->update(['member_card_eligible' => true]);
    $cards->revoke($tenant, $member->id);
    $expect($cards->resolve($token)['Valid'] === false);
    $expect($cards->verification($tenant, $member->id)['Url'] === $qr['Url']);
    $cards->rotate($tenant, $member->id);
    $expect($cards->resolve($token)['Valid'] === false);
    $expect($cards->verification($tenant, $member->id)['Url'] !== $qr['Url']);
    $crossTenantDenied = false;
    try { $cards->ensureCard($other, $member->id); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException) { $crossTenantDenied = true; }
    $expect($crossTenantDenied);
    $latest = $cards->verification($tenant, $member->id);
    $racing = new class extends App\ClubUnio\Services\MemberCardService {
        public function snapshot(App\ClubUnio\Models\Tenant $tenant, int $memberId): array {
            $snapshot = parent::snapshot($tenant, $memberId);
            parent::rotate($tenant, $memberId);
            return $snapshot;
        }
    };
    $race = $racing->resolve(basename(parse_url($latest['Url'], PHP_URL_PATH)));
    $expect($race['Valid'] === false && $race['Name'] === null);
    echo "PG card branding/DTO, stable QR, live HTTP DE/EN, privacy, revoke/rotate, tenant isolation and Wallet revision/auth passed; rollback.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic check failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine()."\n");
    $failed = true;
} finally {
    $db->rollBack();
}
if ($failed ?? false) exit(1);
