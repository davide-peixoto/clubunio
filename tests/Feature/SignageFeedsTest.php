<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\SettingsService;
use App\ClubUnio\Services\SignageFeedService;
use App\Livewire\ClubUnio\SignageFeeds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SignageFeedsTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to rolled-back ClubUnio tests.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $this->tenant = Tenant::create(['slug' => 'feeds-'.$suffix, 'name' => 'Feed fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'feeds-'.$suffix, 'display_name' => 'Feed fixture', 'password' => password_hash('fixture', PASSWORD_BCRYPT), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->actingAs($this->actor, 'clubunio');
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if ($this->db) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function setting(string $key, string $value, bool $enabled = true): void
    {
        $this->db->table('clubora.tenant_settings')->updateOrInsert(['tenant_id' => $this->tenant->id, 'area' => 'integrations', 'key' => $key], ['label' => $key, 'encrypted_value' => Crypt::encryptString($value), 'value_type' => 'string', 'enabled' => $enabled]);
    }

    private function weather(): array
    {
        foreach (['SIGNAGE_WEATHER_ENABLED' => '1', 'SIGNAGE_WEATHER_LAT' => '47.46408', 'SIGNAGE_WEATHER_LON' => '7.80888', 'SIGNAGE_WEATHER_LABEL' => 'Sissach'] as $key => $value) {
            $this->setting($key, $value);
        }

        return ['current' => ['temperature_2m' => 14.3, 'wind_speed_10m' => 8, 'weather_code' => 61, 'time' => '2026-10-12T21:15'], 'daily' => ['time' => ['2026-10-12', '2026-10-13', '2026-10-14'], 'weather_code' => [61, 3, 0], 'temperature_2m_max' => [16, 18, 19], 'temperature_2m_min' => [7, 8, 9]]];
    }

    private function read(string $feed): array
    {
        return app(SignageFeedService::class)->read($this->actor, $this->tenant, $feed);
    }

    public function test_disabled_feeds_and_initial_render_do_not_call_providers(): void
    {
        Http::fake();
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'secret-disabled', false);
        $this->assertSame('disabled', $this->read('instagram')['state']);
        $this->assertSame('disabled', $this->read('weather')['state']);
        Livewire::test(SignageFeeds::class, ['tenantId' => $this->tenant->id])->assertSee('Wird geladen');
        Http::assertNothingSent();
    }

    public function test_instagram_activation_is_admin_only_scoped_audited_and_detects_stale_state(): void
    {
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'fixture-secret', false);
        $id = $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->value('id');
        $service = app(SettingsService::class);
        $service->enableInstagram($this->actor, $this->tenant, $id, true, false);
        $this->assertTrue($this->db->table('clubora.tenant_settings')->where('id', $id)->value('enabled'));
        try {
            $service->enableInstagram($this->actor, $this->tenant, $id, false, false);
            $this->fail('Stale state accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enabled', $e->errors());
        }
        $this->assertSame(1, $this->db->table('clubora.setting_changes')->where('tenant_id', $this->tenant->id)->count());
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->update(['role' => 'office']);
        Http::assertNothingSent();
        $this->expectException(HttpException::class);
        $service->enableInstagram($this->actor, $this->tenant, $id, false, true);
    }

    public function test_weather_success_is_cached_and_keeps_stale_data_on_error(): void
    {
        $body = $this->weather();
        Http::fake(['api.open-meteo.com/*' => Http::sequence()->push($body)->pushStatus(503)->pushStatus(503)]);
        $first = $this->read('weather');
        $this->assertSame('ready', $first['state']);
        $this->assertSame('Regen', $first['data']['text']);
        $this->assertSame(14.3, $first['data']['temperature']);
        $this->assertSame($first, $this->read('weather'));
        Http::assertSentCount(1);
        $this->travel(16)->minutes();
        $stale = $this->read('weather');
        $this->assertSame('stale', $stale['state']);
        $this->assertSame($first['data'], $stale['data']);
        $this->assertSame($first['updated'], $stale['updated']);
        $this->read('weather');
        Http::assertSentCount(2);
        $this->travel(7)->hours();
        $this->assertSame('unavailable', $this->read('weather')['state']);
    }

    public function test_weather_invalid_configuration_and_payload_are_not_zero_weather(): void
    {
        $body = $this->weather();
        $this->setting('SIGNAGE_WEATHER_LAT', '200');
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => null]])]);
        $this->assertSame('unavailable', $this->read('weather')['state']);
        Http::assertNothingSent();
        $this->setting('SIGNAGE_WEATHER_LAT', '47');
        $result = $this->read('weather');
        $this->assertSame('unavailable', $result['state']);
        $this->assertNull($result['data']);
        Http::assertSentCount(1);
    }

    public function test_instagram_safe_images_only_and_token_never_in_state_or_query(): void
    {
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'private-fixture-token');
        Http::fake(['graph.instagram.com/*' => Http::response(['data' => [
            ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/photo.jpg', 'permalink' => 'https://www.instagram.com/p/one/', 'caption' => '<script>unsafe</script>'],
            ['media_type' => 'VIDEO', 'media_url' => 'https://scontent.cdninstagram.com/video.mp4', 'thumbnail_url' => 'https://a.fbcdn.net/thumb.jpg', 'permalink' => 'https://instagram.com/reel/two/'],
            ['media_url' => 'https://cdninstagram.com.attacker.invalid/photo.jpg', 'permalink' => 'https://instagram.com/p/three/'],
            ['media_url' => 'https://cdninstagram.com/photo.jpg', 'permalink' => 'javascript:alert(1)'],
        ]])]);
        $result = $this->read('instagram');
        $this->assertCount(2, $result['data']);
        $this->assertStringNotContainsString('private-fixture-token', json_encode($result));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer private-fixture-token') && ! str_contains($r->url(), 'private-fixture-token'));
        Livewire::test(SignageFeeds::class, ['tenantId' => $this->tenant->id])->call('refreshFeeds')->assertSeeHtml('&lt;script&gt;unsafe&lt;/script&gt;')->assertDontSeeHtml('<script>unsafe</script>');
    }

    public function test_instagram_rotation_disable_and_failure_redaction(): void
    {
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'first-token');
        Http::fake(['graph.instagram.com/*' => Http::sequence()->push(['data' => []])->push(['error' => ['message' => 'secret-second-token']], 401)]);
        $this->assertSame('ready', $this->read('instagram')['state']);
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'second-token');
        Log::spy();
        $this->assertSame('unavailable', $this->read('instagram')['state']);
        Log::shouldHaveReceived('warning')->once()->with('ClubUnio signage feed unavailable', ['tenant_id' => $this->tenant->id, 'feed' => 'instagram', 'http_status' => 401]);
        $this->setting('INSTAGRAM_ACCESS_TOKEN', 'second-token', false);
        $this->assertSame('disabled', $this->read('instagram')['state']);
        Http::assertSentCount(2);
    }

    public function test_feed_access_rechecked_after_role_revoked(): void
    {
        Http::fake();
        $component = Livewire::test(SignageFeeds::class, ['tenantId' => $this->tenant->id]);
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $component->call('refreshFeeds')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_foreign_tenant_cannot_load_feeds(): void
    {
        $other = Tenant::create(['slug' => 'foreign-'.bin2hex(random_bytes(6)), 'name' => 'Other']);
        Livewire::test(SignageFeeds::class, ['tenantId' => $other->id])->assertForbidden();
    }

    public function test_tenant_property_cannot_be_changed_in_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SignageFeeds::class, ['tenantId' => $this->tenant->id])->set('tenantId', $this->tenant->id + 1);
    }

    public function test_feed_data_cannot_be_injected_from_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SignageFeeds::class, ['tenantId' => $this->tenant->id])->set('feeds', ['instagram' => ['state' => 'ready']]);
    }
}
