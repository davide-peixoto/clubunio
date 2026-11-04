<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

final class MobileExternalConfigurationTest extends TestCase
{
    private function configured(array $values, callable $check): void
    {
        $before = [];
        foreach ($values as $key => $value) {
            $before[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
            $_ENV[$key] = $value; $_SERVER[$key] = $value;
        }
        try { $check(); }
        finally {
            foreach ($before as $key => [$hadEnv, $env, $hadServer, $server]) {
                if ($hadEnv) $_ENV[$key] = $env; else unset($_ENV[$key]);
                if ($hadServer) $_SERVER[$key] = $server; else unset($_SERVER[$key]);
            }
        }
    }

    public function test_identity_uses_explicit_origins_tenants_and_its_dedicated_mailer(): void
    {
        $this->configured(['CLUBUNIO_MOBILE_IDENTITY_ENABLED' => 'true', 'CLUBUNIO_MOBILE_IDENTITY_MAIL_ENABLED' => 'false',
            'CLUBUNIO_MOBILE_IDENTITY_ORIGINS' => 'https://synthetic.example,https://custom.example:8443',
            'CLUBUNIO_MOBILE_IDENTITY_TENANT_SLUGS' => 'synthetic-club'], function () {
                $config = require dirname(__DIR__, 2).'/config/clubunio-public-identity.php';
                $this->assertTrue($config['enabled']); $this->assertFalse($config['mail_enabled']);
                $this->assertSame(['https://synthetic.example', 'https://custom.example:8443'], $config['origins']);
                $this->assertSame(['synthetic-club'], $config['tenant_slugs']);
                $this->assertSame('clubunio_identity', $config['mailer']);
            });
        $this->configured(['CLUBUNIO_MOBILE_IDENTITY_ENABLED' => 'unknown', 'CLUBUNIO_MOBILE_IDENTITY_ORIGINS' => 'https://synthetic.example/path',
            'CLUBUNIO_MOBILE_IDENTITY_TENANT_SLUGS' => '../other'], function () {
                $config = require dirname(__DIR__, 2).'/config/clubunio-public-identity.php';
                $this->assertFalse($config['enabled']); $this->assertSame([], $config['origins']); $this->assertSame([], $config['tenant_slugs']);
            });
    }

    public function test_dedicated_smtp_requires_tls_without_connecting_or_inheriting_mail_url(): void
    {
        $this->configured(['CLUBUNIO_IDENTITY_SMTP_HOST' => 'smtp.synthetic.invalid', 'CLUBUNIO_IDENTITY_SMTP_SCHEME' => 'smtp',
            'CLUBUNIO_IDENTITY_SMTP_PORT' => '587'], function () {
                $config = require dirname(__DIR__, 2).'/config/clubunio-identity-smtp.php';
                $this->assertTrue($config['require_tls']); $this->assertArrayNotHasKey('url', $config);
                $transport = (new EsmtpTransportFactory)->create(new Dsn($config['scheme'], $config['host'], null, null, $config['port'], $config));
                $this->assertTrue($transport->isTlsRequired()); // Constructor only: never start/send/connect.
            });
        foreach ([['http', '465', 'smtp.synthetic.invalid'], ['smtps', '70000', 'smtp.synthetic.invalid'], ['smtps', '465', 'https://other.invalid']] as [$scheme, $port, $host]) {
            $this->configured(['CLUBUNIO_IDENTITY_SMTP_SCHEME' => $scheme, 'CLUBUNIO_IDENTITY_SMTP_PORT' => $port, 'CLUBUNIO_IDENTITY_SMTP_HOST' => $host], function () {
                $config = require dirname(__DIR__, 2).'/config/clubunio-identity-smtp.php'; $this->assertNull($config['host']);
            });
        }
    }

    public function test_push_registry_is_operable_without_credentials_discovery_and_rejects_invalid_scope(): void
    {
        $app = ['platform' => 'android', 'origin' => 'https://custom.example:8443', 'fixed_tenant_id' => 7,
            'project_id' => 'synthetic-project', 'credentials_file' => '/private/nonexistent/fcm.json'];
        $this->configured(['CLUBUNIO_MOBILE_PUSH_ENABLED' => 'true', 'CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED' => 'false',
            'CLUBUNIO_MOBILE_PUSH_APPS' => json_encode(['ch.example.custom' => $app], JSON_THROW_ON_ERROR)], function () use ($app) {
                $config = require dirname(__DIR__, 2).'/config/clubunio-mobile-push.php';
                $this->assertTrue($config['enabled']); $this->assertFalse($config['delivery_enabled']);
                $this->assertSame(['ch.example.custom' => $app], $config['apps']); // No credential read occurs in configuration.
            });
        foreach ([$app + ['private_key' => 'not-allowed'], array_replace($app, ['fixed_tenant_id' => '7']), array_replace($app, ['origin' => 'http://custom.example'])] as $bad) {
            $this->configured(['CLUBUNIO_MOBILE_PUSH_APPS' => json_encode(['ch.example.custom' => $bad], JSON_THROW_ON_ERROR)], function () {
                $config = require dirname(__DIR__, 2).'/config/clubunio-mobile-push.php'; $this->assertSame([], $config['apps']);
            });
        }
    }
}
