<?php

namespace Tests\Feature;

use App\ClubUnio\Services\EventAppLinks;
use Tests\TestCase;

class EventAppLinksTest extends TestCase
{
    public function test_apple_association_scopes_events_only_and_prefers_the_custom_app(): void
    {
        config(['clubunio-event-links.apple_team' => 'XP7296D478']);
        $service = new EventAppLinks;
        $custom = $service->apple('stage-clubunio.svsissach.ch');
        $this->assertSame('XP7296D478.com.my.sportvereinSissach.test', $custom['applinks']['details'][0]['appIDs'][0]);
        $this->assertSame('XP7296D478.com.my.clubunio.core', $custom['applinks']['details'][1]['appIDs'][0]);
        $this->assertSame([['/' => '/event-check-in/*']], $custom['applinks']['details'][0]['components']);
        $this->assertStringNotContainsString('/member-card/', json_encode($custom));
        $this->assertCount(1, $service->apple('stage.clubunio.com')['applinks']['details']);
    }

    public function test_missing_credentials_do_not_advertise_an_invented_app_identity(): void
    {
        config(['clubunio-event-links.apple_team' => '', 'clubunio-event-links.android_fingerprints.core' => []]);
        $service = new EventAppLinks;
        $this->assertSame([], $service->apple('stage.clubunio.com')['applinks']['details']);
        $this->assertSame([], $service->android('stage.clubunio.com'));
    }

    public function test_android_publishes_only_valid_signing_fingerprints(): void
    {
        config(['clubunio-event-links.android_fingerprints.core' => [str_repeat('ab', 32), 'NOT-A-CERTIFICATE']]);
        $result = (new EventAppLinks)->android('stage.clubunio.com');
        $this->assertSame('com.my.clubunio.core', $result[0]['target']['package_name']);
        $this->assertSame([implode(':', array_fill(0, 32, 'AB'))], $result[0]['target']['sha256_cert_fingerprints']);
    }

    public function test_unconfigured_hosts_fail_closed(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new EventAppLinks)->apple('unrelated.example');
    }

    public function test_association_routes_are_public_https_json_without_redirects(): void
    {
        config(['clubunio-event-links.apple_team' => 'XP7296D478']);
        foreach (['/apple-app-site-association', '/.well-known/apple-app-site-association', '/.well-known/assetlinks.json'] as $path) {
            $this->get('https://stage.clubunio.com'.$path)->assertOk()->assertHeader('Content-Type', 'application/json');
        }
        $this->get('http://stage.clubunio.com/apple-app-site-association')->assertNotFound();
        $this->get('https://unrelated.example/apple-app-site-association')->assertNotFound();
    }
}
