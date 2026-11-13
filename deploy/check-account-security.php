<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountSecurity;
use App\ClubUnio\Services\TenantSecurityRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['clubunio_dev', 'clubunio_stage'], true)) {
    exit(1);
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$security = app(AccountSecurity::class);
$db = $security->db();
$require = static function (bool $condition, string $step): void {
    if (!$condition) throw new RuntimeException($step);
};
$status = 0;
try {
    $require($db->getDatabaseName() === $argv[1] && $db->selectOne('SELECT current_database() AS name')->name === $argv[1], 'Target identity');
    $require($security->enabled() && config('clubunio-security.enroll_since') !== null, 'Configuration');
    $db->beginTransaction();
    $tenant = Tenant::query()->firstOrFail();
    $make = static function (): array {
        $account = new Account;
        $password = bin2hex(random_bytes(24));
        $account->forceFill(['username' => 'security-check-'.bin2hex(random_bytes(12)).'@example.invalid',
            'display_name' => 'Synthetic security check', 'password' => Hash::make($password), 'active' => true, 'api_only' => false])->save();
        return [$account, $password];
    };
    [$target] = $make();
    [$operator, $password] = $make();
    $require($security->mustEnroll($target), 'Wizard required');
    $db->table('clubunio.tenant_accounts')->insert([
        ['tenant_id' => $tenant->id, 'account_id' => $target->id, 'role' => 'member'],
        ['tenant_id' => $tenant->id, 'account_id' => $operator->id, 'role' => 'office'],
    ]);
    $targetLink = (int) $db->table('clubunio.tenant_accounts')->where('account_id', $target->id)->value('id');
    $setup = static function (Account $account, string $code) use ($security, $require): string {
        $secret = $security->totp()->generateSecretKey();
        $codes = $security->enroll($account, $code, ['totp_encrypted' => Crypt::encryptString($secret)]);
        $require(count($codes) === 8 && !$security->mustEnroll($account), 'Enroll');
        return $secret;
    };
    $targetSecret = $setup($target, $security->invitation($target));
    $operatorSecret = $setup($operator, $security->invitation($operator));
    $stamp = $security->verifySecondFactor($operator, $security->totp()->getCurrentOtp($operatorSecret));
    $request = Request::create(config('clubunio-security.origin').'/account/recovery', 'POST');
    $request->setLaravelSession(app('session')->driver('array'));
    $request->session()->put(['clubunio.security.clubunio' => $stamp, 'clubunio.security.verified_at.clubunio' => time()]);
    $code = app(TenantSecurityRecovery::class)->reset($request, $operator, $tenant, $targetLink, [
        'operator_password' => $password, 'reason' => 'Synthetic rollback acceptance', 'identity_verified' => true,
    ]);
    $require($security->resetPending($target), 'Reset pending');
    try {
        $security->verifySecondFactor($target, $security->totp()->getCurrentOtp($targetSecret));
        throw new RuntimeException('Old factor accepted');
    } catch (HttpExceptionInterface $e) {
        $require($e->getStatusCode() === 401, 'Old factor rejected');
    }
    $setup($target, $code);
    $require(!$security->resetPending($target), 'Reset completed');
    echo $argv[1].": wizard, office reset, revoked factor and re-enrollment verified; synthetic data rolled back.\n";
} catch (Throwable $e) {
    // Never emit passwords, identifiers or SQL bindings from a live environment.
    fwrite(STDERR, 'Account security acceptance failed: '.$e::class.' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);
    $status = 1;
} finally {
    if ($db->transactionLevel() > 0) $db->rollBack();
}
exit($status);
