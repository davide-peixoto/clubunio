<?php

namespace Tests\Feature;

use App\ClubUnio\Wallet\ApplePassBundle;
use Tests\TestCase;

class ApplePassBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/clubunio-wallet-test-'.bin2hex(random_bytes(10)); mkdir($this->dir, 0700);
        file_put_contents($this->dir.'/openssl.cnf', "[ req ]\ndistinguished_name = dn\nprompt = no\n[ dn ]\nCN = Synthetic Wallet\n");
        $options = ['config' => $this->dir.'/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $caKey = openssl_pkey_new($options);
        $caCsr = openssl_csr_new(['commonName' => 'Synthetic wallet CA'], $caKey, $options);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 2, $options, 1);
        openssl_x509_export_to_file($ca, $this->dir.'/wwdr.pem');
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'Synthetic Pass', 'organizationalUnitName' => 'TEAM000001', 'UID' => 'pass.com.example.member'], $key, $options);
        $cert = openssl_csr_sign($csr, $ca, $caKey, 1, $options, 2);
        openssl_x509_export_to_file($cert, $this->dir.'/cert.pem');
        openssl_pkey_export_to_file($key, $this->dir.'/key.pem', null, $options);
        config(['clubunio-wallet.enabled' => true, 'clubunio-wallet.web_service_origin' => 'https://wallet.example.invalid',
            'clubunio-wallet.pass_type_identifier' => 'pass.com.example.member', 'clubunio-wallet.team_identifier' => 'TEAM000001',
            'clubunio-wallet.certificate_path' => $this->dir.'/cert.pem', 'clubunio-wallet.private_key_path' => $this->dir.'/key.pem',
            'clubunio-wallet.wwdr_certificate_path' => $this->dir.'/wwdr.pem', 'clubunio-wallet.private_key_password' => '',
            'clubunio-languages.enabled' => ['de' => 'Deutsch', 'en' => 'English']]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) { @unlink($file); } @rmdir($this->dir);
        parent::tearDown();
    }

    private function pass(): array
    {
        return ['formatVersion' => 1, 'passTypeIdentifier' => 'pass.com.example.member', 'teamIdentifier' => 'TEAM000001',
            'serialNumber' => 'synthetic-serial', 'webServiceURL' => 'https://wallet.example.invalid/wallet', 'description' => 'wallet.member_card',
            'organizationName' => 'Synthetic Club', 'authenticationToken' => str_repeat('a', 64),
            'generic' => ['primaryFields' => [['key' => 'member', 'label' => 'wallet.member', 'value' => 'Synthetic Member']]]];
    }

    private function images(): array
    {
        $image = imagecreatetruecolor(10, 10); imagefill($image, 0, 0, imagecolorallocate($image, 160, 32, 48));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return array_fill_keys(['icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png'], $bytes);
    }

    public function test_real_detached_signature_manifest_and_zip_preserve_assets_and_only_enabled_languages(): void
    {
        $service = new ApplePassBundle;
        $this->assertTrue($service->available());
        $bytes = $service->build($this->pass(), $this->images());
        file_put_contents($this->dir.'/pass.pkpass', $bytes);
        $zip = new \ZipArchive; $this->assertTrue($zip->open($this->dir.'/pass.pkpass'));
        $manifestBytes = $zip->getFromName('manifest.json'); $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest as $name => $sha) { $this->assertSame($sha, sha1($zip->getFromName($name))); }
        $this->assertArrayHasKey('de.lproj/pass.strings', $manifest);
        $this->assertArrayHasKey('en.lproj/pass.strings', $manifest);
        $this->assertArrayNotHasKey('fr.lproj/pass.strings', $manifest);
        $this->assertStringContainsString('wallet.valid', $zip->getFromName('de.lproj/pass.strings'));
        $this->assertSame('Synthetic Member', json_decode($zip->getFromName('pass.json'), true)['generic']['primaryFields'][0]['value']);
        $this->assertFalse($zip->getFromName('key.pem'));
        file_put_contents($this->dir.'/signature', $zip->getFromName('signature')); file_put_contents($this->dir.'/manifest.json', $manifestBytes); $zip->close();
        $ok = openssl_cms_verify($this->dir.'/manifest.json', OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
            null, [], null, $this->dir.'/verified.json', null, $this->dir.'/signature', OPENSSL_ENCODING_DER);
        $errors = []; while ($error = openssl_error_string()) { $errors[] = $error; }
        $this->assertTrue($ok, 'Synthetic detached signature: '.implode('; ', $errors));
        $this->assertSame($manifestBytes, file_get_contents($this->dir.'/verified.json'));
        file_put_contents($this->dir.'/manifest.json', $manifestBytes.' ');
        $this->assertFalse(openssl_cms_verify($this->dir.'/manifest.json', OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
            null, [], null, $this->dir.'/tampered.json', null, $this->dir.'/signature', OPENSSL_ENCODING_DER));
        while (openssl_error_string()) {} // Expected tamper error, no secret content.
    }

    public function test_wrong_pass_identity_or_mismatched_intermediate_never_advertises_availability(): void
    {
        $service = new ApplePassBundle; $this->assertTrue($service->available());
        config(['clubunio-wallet.pass_type_identifier' => 'pass.com.other']); $this->assertFalse($service->available());
        config(['clubunio-wallet.pass_type_identifier' => 'pass.com.example.member', 'clubunio-wallet.team_identifier' => 'OTHERTEAM00']);
        $this->assertFalse($service->available());
        config(['clubunio-wallet.team_identifier' => 'TEAM000001', 'clubunio-wallet.wwdr_certificate_path' => $this->dir.'/cert.pem']);
        $this->assertFalse($service->available());
    }

    public function test_origin_must_be_https_and_have_no_credentials_path_or_query(): void
    {
        foreach (['http://wallet.example.invalid', 'https://user@wallet.example.invalid', 'https://wallet.example.invalid/path', 'https://wallet.example.invalid?token=x'] as $origin) {
            config(['clubunio-wallet.web_service_origin' => $origin]); $this->assertFalse((new ApplePassBundle)->available());
        }
    }
}
