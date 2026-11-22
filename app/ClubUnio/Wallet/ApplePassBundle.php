<?php

namespace App\ClubUnio\Wallet;

use Illuminate\Support\Facades\Lang;

/** Small, dependency-free PassKit signer. Private materials never enter the ZIP. */
class ApplePassBundle
{
    public const MAX_BYTES = 5_242_880;

    public function available(): bool
    {
        try { $this->credentials(); return true; } catch (\Throwable) { return false; }
    }

    public function origin(): string
    {
        $origin = rtrim((string) config('clubunio-wallet.web_service_origin'), '/');
        $p = parse_url($origin);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || ! in_array($p['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('wallet_origin_unavailable');
        }
        return $origin;
    }

    private function privateFile(string $setting): string
    {
        $path = realpath((string) config('clubunio-wallet.'.$setting));
        $public = realpath(public_path());
        if (! $path || ! is_file($path) || ! is_readable($path)
            || ($public && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $public).'/'))) {
            throw new \RuntimeException('wallet_signing_unavailable');
        }
        return $path;
    }

    /** @return array{certificate:string,key:string,password:string,wwdr:string} */
    public function credentials(): array
    {
        if (! config('clubunio-wallet.enabled') || ! function_exists('openssl_cms_sign')
            || ! class_exists(\ZipArchive::class) || ! function_exists('imagecreatetruecolor')
            || ! function_exists('curl_version') || ! defined('CURL_VERSION_HTTP2')
            || ! (curl_version()['features'] & CURL_VERSION_HTTP2)) {
            throw new \RuntimeException('wallet_not_configured');
        }
        $this->origin();
        $certificate = $this->privateFile('certificate_path');
        $key = $this->privateFile('private_key_path');
        $wwdr = $this->privateFile('wwdr_certificate_path');
        $password = (string) config('clubunio-wallet.private_key_password', '');
        $cert = openssl_x509_read(file_get_contents($certificate));
        $private = openssl_pkey_get_private(file_get_contents($key), $password);
        $parsed = $cert ? openssl_x509_parse($cert) : false;
        $intermediate = openssl_x509_read(file_get_contents($wwdr));
        $issuer = $intermediate ? openssl_x509_parse($intermediate) : false;
        $subject = $parsed['subject'] ?? [];
        if (! $cert || ! $private || ! openssl_x509_check_private_key($cert, $private)
            || ! $intermediate || openssl_x509_verify($cert, openssl_pkey_get_public($intermediate)) !== 1
            || ($issuer['validFrom_time_t'] ?? PHP_INT_MAX) > time()
            || ($issuer['validTo_time_t'] ?? 0) < time() + 300
            || ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time()
            || ($parsed['validTo_time_t'] ?? 0) < time() + 300
            || ($subject['UID'] ?? '') !== config('clubunio-wallet.pass_type_identifier')
            || ($subject['OU'] ?? '') !== config('clubunio-wallet.team_identifier')) {
            throw new \RuntimeException('wallet_signing_unavailable');
        }
        return compact('certificate', 'key', 'password', 'wwdr');
    }

    public function signingVersion(): string
    {
        $keys = $this->credentials();
        return hash('sha256', hash_file('sha256', $keys['certificate']).'|'.hash_file('sha256', $keys['wwdr']).'|'.$this->origin());
    }

    /** Stable keys in pass.json; local catalog values only, never member/club text. */
    public function localizations(): array
    {
        $messages = ['wallet.member_card' => 'Mitgliederausweis', 'wallet.member' => 'Mitglied',
            'wallet.status' => 'Status', 'wallet.valid' => 'Gültig', 'wallet.invalid' => 'Ungültig',
            'wallet.updated' => 'Letzte Aktualisierung', 'wallet.verify' => 'Online prüfen', 'wallet.note' => 'Hinweis',
            'wallet.live' => 'Der aktuelle Mitgliedschaftsstatus wird beim Scannen online geprüft. Wallet-Aktualisierungen können verzögert eintreffen.'];
        $result = [];
        foreach (array_keys(config('clubunio-languages.enabled', [])) as $locale) {
            if (! preg_match('/\A[a-z]{2}\z/D', $locale)) { continue; }
            $result[$locale] = [];
            foreach ($messages as $key => $message) { $result[$locale][$key] = Lang::get($message, [], $locale); }
        }
        return $result;
    }

    public function build(array $pass, array $images): string
    {
        $keys = $this->credentials();
        if (($pass['passTypeIdentifier'] ?? null) !== config('clubunio-wallet.pass_type_identifier')
            || ($pass['teamIdentifier'] ?? null) !== config('clubunio-wallet.team_identifier')
            || ($pass['webServiceURL'] ?? null) !== $this->origin().'/wallet') {
            throw new \RuntimeException('wallet_identity_mismatch');
        }
        $files = ['pass.json' => json_encode($pass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        foreach (['icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png'] as $name) {
            if (! isset($images[$name]) || ! str_starts_with($images[$name], "\x89PNG\r\n\x1a\n")) {
                throw new \RuntimeException('wallet_artwork_unavailable');
            }
            $files[$name] = $images[$name];
        }
        foreach ($this->localizations() as $locale => $messages) {
            $strings = '';
            foreach ($messages as $key => $value) {
                $strings .= json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).' = '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).";\n";
            }
            $files[$locale.'.lproj/pass.strings'] = $strings;
        }
        $manifest = [];
        foreach ($files as $name => $bytes) { $manifest[$name] = sha1($bytes); }
        $files['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $dir = sys_get_temp_dir().'/clubunio-wallet-'.bin2hex(random_bytes(16));
        if (! mkdir($dir, 0700)) { throw new \RuntimeException('wallet_temporary_unavailable'); }
        try {
            file_put_contents($dir.'/manifest.json', $files['manifest.json']);
            if (! openssl_cms_sign($dir.'/manifest.json', $dir.'/signature', 'file://'.$keys['certificate'],
                ['file://'.$keys['key'], $keys['password']], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER, $keys['wwdr'])) {
                throw new \RuntimeException('wallet_signature_failed');
            }
            $files['signature'] = file_get_contents($dir.'/signature');
            $zip = new \ZipArchive;
            if ($zip->open($dir.'/pass.pkpass', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('wallet_package_failed');
            }
            foreach ($files as $name => $bytes) { $zip->addFromString($name, $bytes); }
            $zip->close();
            $bytes = file_get_contents($dir.'/pass.pkpass');
            if (! is_string($bytes) || strlen($bytes) > self::MAX_BYTES) { throw new \RuntimeException('wallet_package_size'); }
            return $bytes;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dir);
        }
    }
}
