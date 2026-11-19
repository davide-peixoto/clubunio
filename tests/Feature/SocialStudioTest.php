<?php

namespace Tests\Feature;

use App\ClubUnio\Services\{PermissionCatalog, SocialMetaTestPublisher, SocialStudioData};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SocialStudioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $db->statement('CREATE TABLE clubunio.social_media (id TEXT, tenant_id INTEGER)');
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    public function test_private_media_is_reencoded_and_export_dimensions_are_checked(): void
    {
        $data = app(SocialStudioData::class);
        $original = $data->storeImage(7, UploadedFile::fake()->image('photo.jpg', 32, 45));
        self::assertStringStartsWith('social-studio/7/', $original);
        self::assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($original))['mime']);
        $export = $data->storeImage(7, UploadedFile::fake()->image('post.jpg', 1080, 1350), true, 1350);
        self::assertSame('image/jpeg', getimagesizefromstring(Storage::disk('local')->get($export))['mime']);
        $this->expectException(ValidationException::class);
        $data->storeImage(7, UploadedFile::fake()->image('wrong.jpg', 1079, 1350), true, 1350);
    }

    public function test_design_accepts_only_normalized_tenant_owned_media_and_crops(): void
    {
        $id = 'a93b41c8-f8a5-46f4-8a9d-79f302d38026';
        DB::connection('clubunio')->table('clubunio.social_media')->insert(['id' => $id, 'tenant_id' => 7]);
        $design = ['format' => 'portrait', 'background' => '#ffffff', 'layers' => [[
            'type' => 'image', 'media' => $id, 'x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350, 'angle' => 0,
            'crop' => ['x' => .2, 'y' => .25, 'w' => .4, 'h' => .6],
        ]]];
        self::assertSame($design, app(SocialStudioData::class)->design(7, $design));
        foreach ([[8, $design], [7, array_replace_recursive($design, ['layers' => [['crop' => ['w' => .95]]]])]] as [$tenant, $candidate]) {
            try {
                app(SocialStudioData::class)->design($tenant, $candidate);
                self::fail('Invalid design accepted');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('social', $e->errors());
            }
        }
    }

    public function test_meta_requires_exact_dev_origin_and_explicit_test_tenant_and_never_falls_back(): void
    {
        $publisher = app(SocialMetaTestPublisher::class);
        $origin = 'https://dev.clubunio.ch';
        config(['app.url' => $origin, 'clubunio-social.tenant_id' => 7,
            'clubunio-social.instagram' => ['id' => '123456', 'token' => 'test-only']]);
        self::assertNull($publisher->target(7, 'instagram', $origin));
        config(['clubunio-social.test_enabled' => true]);
        self::assertSame('123456', $publisher->target(7, 'instagram', $origin)['id']);
        self::assertNull($publisher->target(8, 'instagram', $origin));
        self::assertNull($publisher->target(7, 'facebook', $origin));
        self::assertNull($publisher->target(7, 'instagram', 'https://clubunio.ch'));
        config(['app.url' => 'https://clubunio.ch']);
        self::assertNull($publisher->target(7, 'instagram', $origin));
        Http::assertNothingSent();
        self::assertContains('social_media.send', PermissionCatalog::keys());
        self::assertNotContains('social_media.send', PermissionCatalog::readOnly());
    }

    public function test_meta_uses_only_the_mock_test_destination_and_preserves_ambiguous_outcomes(): void
    {
        $publisher = app(SocialMetaTestPublisher::class);
        $target = ['id' => '123456', 'token' => 'test-only'];
        Storage::disk('local')->put('private-test.jpg', 'fake-image');
        $post = (object) ['id' => 'a93b41c8-f8a5-46f4-8a9d-79f302d38026', 'caption' => 'Synthetic match', 'image_path' => 'private-test.jpg'];
        Http::fake([
            'graph.instagram.com/v27.0/123456/media' => Http::response(['id' => '345678']),
            'graph.instagram.com/v27.0/123456/media_publish' => Http::response(['id' => '456789']),
            'graph.facebook.com/v27.0/123456/photos' => Http::response([], 503),
        ]);
        self::assertSame(['state' => 'published', 'remote_id' => '456789'], $publisher->deliver(7, $post, 'instagram', $target));
        self::assertSame(['state' => 'unknown', 'error_code' => 'check_meta'], $publisher->deliver(7, $post, 'facebook', $target));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/media') && ($request['caption'] ?? null) === '[ClubUnio DEV TEST] Synthetic match');
    }

    public function test_social_translation_groups_match(): void
    {
        self::assertSame(array_keys(require base_path('lang/en/social.php')), array_keys(require base_path('lang/de/social.php')));
    }

    public function test_stage_module_defaults_to_disabled_and_rejects_direct_controller_access(): void
    {
        self::assertFalse(config('clubunio-social.enabled'));
        self::assertFalse(\App\ClubUnio\Pages\SocialStudio::canAccess());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(\App\ClubUnio\Http\SocialStudioController::class)(\Illuminate\Http\Request::create('/social-data/state'), 'state');
    }
}
