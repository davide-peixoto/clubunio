<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileClubhouseDocumentation;
use App\ClubUnio\Services\ClubhouseAccess;
use App\ClubUnio\Services\ClubhouseHours;
use App\ClubUnio\Services\ClubhouseMenus;
use App\ClubUnio\Services\ClubhouseService;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\TenantDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ClubhouseFixture;
use Tests\TestCase;

class ClubhouseTest extends TestCase
{
    use ClubhouseFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clubhouseFixture();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    private function grant(array $replace = []): void
    {
        app(ClubhouseAccess::class)->grant($this->admin, $this->club, $this->trainer->id, array_replace([
            'hours_write' => true, 'menus_write' => true, 'menus_publish' => false, 'active' => true,
            'revision' => 0, 'reason' => 'Synthetic clubhouse assignment',
        ], $replace));
    }

    public function test_additional_role_never_changes_existing_roles_or_other_permissions(): void
    {
        $this->grant();
        $access = app(ClubhouseAccess::class);
        $this->assertTrue($access->allows($this->trainer, $this->club, 'view'));
        $this->assertTrue($access->allows($this->trainer, $this->club, 'hours.write'));
        $this->assertTrue($access->allows($this->trainer, $this->club, 'menus.write'));
        $this->assertFalse($access->allows($this->trainer, $this->club, 'menus.publish'));
        $this->assertFalse($access->allows($this->trainer, $this->otherClub, 'hours.write'));
        $this->assertFalse($access->allows($this->trainer, $this->club, 'unknown'));
        $this->assertFalse(app(ModulePermissionService::class)->allows($this->trainer, $this->club, 'invoices', 'update'));
        $this->assertSame('trainer', DB::connection('clubunio')->table('clubora.tenant_accounts')->where('account_id', 2)->value('role'));
        $this->assertSame(1, DB::connection('clubunio')->table('clubora.clubhouse_events')->count());
        $this->grant(['active' => false, 'revision' => 1]);
        $this->assertFalse($access->allows($this->trainer, $this->club, 'view'));
    }

    public function test_only_admin_may_assign_grants_and_revisions_are_enforced(): void
    {
        try {
            app(ClubhouseAccess::class)->grant($this->trainer, $this->club, 2, []);
            $this->fail('Self grant allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->grant();
        try {
            $this->grant();
            $this->fail('Stale grant accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame(1, DB::connection('clubunio')->table('clubora.clubhouse_grants')->value('revision'));
    }

    public function test_weekly_and_one_off_hours_are_resolved_without_duplicate_intervals(): void
    {
        $hours = app(ClubhouseHours::class);
        $hours->save($this->admin, $this->club, null, $this->opening());
        $hours->save($this->admin, $this->club, null, $this->opening(['weekdays' => [], 'starts_on' => '2026-11-24',
            'intervals' => [['start' => '20:00', 'end' => '23:00', 'overnight' => false]]]));
        $days = $hours->calendar($this->club, '2026-11-23', '2026-11-26');
        $this->assertSame([], $days[0]['intervals']);
        $this->assertCount(1, $days[1]['intervals']);
        $this->assertStringContainsString('18:00:00+01:00', $days[1]['intervals'][0]['start']);
        $this->assertStringContainsString('23:00:00+01:00', $days[1]['intervals'][0]['end']);
        $this->assertSame([], $days[2]['intervals']);
        $this->assertCount(1, $days[3]['intervals']);
    }

    public function test_closed_day_overrides_overnight_carry_and_internal_notes_are_private(): void
    {
        $hours = app(ClubhouseHours::class);
        $hours->save($this->admin, $this->club, null, $this->opening(['weekdays' => [1],
            'intervals' => [['start' => '20:00', 'end' => '02:00', 'overnight' => true]]]));
        $days = $hours->calendar($this->club, '2026-11-23', '2026-11-24');
        $this->assertCount(1, $days[1]['intervals']);
        $hours->saveException($this->admin, $this->club, ['date' => '2026-11-24', 'closed' => true,
            'intervals' => [], 'note' => 'Private staff note', 'revision' => 0]);
        $days = $hours->calendar($this->club, '2026-11-23', '2026-11-24');
        $this->assertSame([], $days[1]['intervals']);
        $this->assertTrue($days[1]['exception']);
        $this->assertStringNotContainsString('Private staff note', json_encode($days));
        $this->assertCount(1, $days[0]['intervals']);
    }

    public function test_single_occurrence_and_future_changes_preserve_past_dates(): void
    {
        $hours = app(ClubhouseHours::class);
        $id = $hours->save($this->admin, $this->club, null, $this->opening());
        $hours->save($this->admin, $this->club, $id, $this->opening(['revision' => 1, 'scope' => 'this', 'on' => '2026-11-24',
            'intervals' => [['start' => '19:00', 'end' => '21:00', 'overnight' => false]]]));
        $days = $hours->calendar($this->club, '2026-11-24', '2026-11-26');
        $this->assertStringContainsString('19:00:00', $days[0]['intervals'][0]['start']);
        $this->assertStringContainsString('18:00:00', $days[2]['intervals'][0]['start']);
        $futureId = $hours->save($this->admin, $this->club, $id, $this->opening(['revision' => 2, 'scope' => 'future', 'on' => '2026-11-26',
            'intervals' => [['start' => '17:00', 'end' => '20:00', 'overnight' => false]]]));
        $this->assertNotSame($id, $futureId);
        $days = $hours->calendar($this->club, '2026-11-24', '2026-11-26');
        $this->assertStringContainsString('19:00:00', $days[0]['intervals'][0]['start']);
        $this->assertStringContainsString('17:00:00', $days[2]['intervals'][0]['start']);
    }

    public function test_invalid_time_windows_and_stale_openings_do_not_write(): void
    {
        $hours = app(ClubhouseHours::class);
        try {
            $hours->save($this->admin, $this->club, null, $this->opening(['intervals' => [['start' => '22:00', 'end' => '18:00', 'overnight' => false]]]));
            $this->fail('Negative interval');
        } catch (ValidationException) {
            $this->assertSame(0, $hours->rules($this->club)->count());
        }
        $id = $hours->save($this->admin, $this->club, null, $this->opening());
        try {
            $hours->save($this->admin, $this->club, $id, $this->opening(['revision' => 99]));
            $this->fail('Stale interval accepted');
        } catch (ValidationException) {
            $this->assertSame(1, $hours->rules($this->club)->where('id', $id)->value('revision'));
        }
    }

    public function test_draft_edits_do_not_change_publications_and_editor_cannot_publish_or_archive_live_menu(): void
    {
        $this->grant();
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->trainer, $this->club, null, $this->menu());
        $this->assertSame([], $menus->published($this->club, 'web'));
        try {
            $menus->transition($this->trainer, $this->club, $id, 1, 'publish');
            $this->fail('Editor published');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $menus->transition($this->admin, $this->club, $id, 1, 'publish');
        app()->setLocale('en');
        $public = $menus->published($this->club, 'web');
        $this->assertSame('Test menu', $public[0]['title']);
        $this->assertTrue($public[0]['language_fallback']);
        $menus->save($this->trainer, $this->club, $id, $this->menu(['revision' => 2, 'body_de' => 'Unapproved change']));
        $this->assertSame("Suppe\nCHF 8.50", $menus->published($this->club, 'web')[0]['body']);
        try {
            $menus->transition($this->trainer, $this->club, $id, 3, 'archive');
            $this->fail('Editor withdrew publication by archiving');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $menus->transition($this->admin, $this->club, $id, 3, 'publish');
        $this->assertSame('Unapproved change', $menus->published($this->club, 'web')[0]['body']);
        $menus->transition($this->admin, $this->club, $id, 4, 'withdraw');
        $this->assertSame([], $menus->published($this->club, 'web'));
    }

    public function test_channels_validity_and_archive_restore_follow_explicit_publishing(): void
    {
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->admin, $this->club, null, $this->menu(['channels' => ['app']]));
        $menus->transition($this->admin, $this->club, $id, 1, 'publish');
        $this->assertSame([], $menus->published($this->club, 'web'));
        $this->assertCount(1, $menus->published($this->club, 'app'));
        $menus->transition($this->admin, $this->club, $id, 2, 'archive');
        $this->assertSame([], $menus->published($this->club, 'app'));
        $menus->transition($this->admin, $this->club, $id, 3, 'restore');
        $this->assertSame([], $menus->published($this->club, 'app'));
        $menus->transition($this->admin, $this->club, $id, 4, 'publish');
        Date::setTestNow('2026-12-01 12:00:00 Europe/Zurich');
        $this->assertSame([], $menus->published($this->club, 'app'));
    }

    public function test_replaced_draft_pdf_does_not_replace_public_pdf_or_leak_storage_paths(): void
    {
        $bytes = [];
        $storage = $this->mock(TenantDocumentStorage::class);
        $storage->shouldReceive('folder')->andReturnUsing(fn ($t, $ns, $id) => 'clubunio/clubhouse/'.$t->id.'/'.$id.'/');
        $storage->shouldReceive('put')->andReturnUsing(function ($t, $folder, $name, $content) use (&$bytes) {
            $bytes[$folder.$name] = $content;
        });
        $storage->shouldReceive('read')->andReturnUsing(function ($t, $folder, $name) use (&$bytes) {
            return $bytes[$folder.$name];
        });
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->admin, $this->club, null, $this->menu(['body_de' => null]));
        $first = "%PDF-1.4\nSynthetic first menu";
        $second = "%PDF-1.4\nSynthetic second menu";
        $menus->upload($this->admin, $this->club, $id, 1, UploadedFile::fake()->createWithContent('first.pdf', $first));
        $menus->transition($this->admin, $this->club, $id, 2, 'publish');
        $menus->upload($this->admin, $this->club, $id, 3, UploadedFile::fake()->createWithContent('second.pdf', $second));
        $this->assertSame($first, $menus->publishedDocument($this->club, $id, 'web'));
        $this->assertSame($second, $menus->document($this->admin, $this->club, $id));
        $this->assertStringNotContainsString('clubunio/clubhouse', json_encode($menus->get($this->admin, $this->club, $id)));
        $this->assertStringNotContainsString('folder', json_encode($menus->published($this->club, 'web')));
    }

    public function test_website_is_opt_in_and_only_serves_published_whitelisted_data(): void
    {
        $this->get('/public/clubhouse/synthetic-club/data')->assertNotFound();
        $menus = app(ClubhouseMenus::class);
        $id = $menus->save($this->admin, $this->club, null, $this->menu());
        $menus->transition($this->admin, $this->club, $id, 1, 'publish');
        app(ClubhouseService::class)->settings($this->admin, $this->club, ['public_enabled' => true, 'timezone' => 'Europe/Zurich', 'revision' => 1]);
        $response = $this->get('/public/clubhouse/synthetic-club/data?lang=en')->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')->assertJsonPath('data.menus.0.title', 'Test menu');
        $this->assertStringNotContainsString('published_snapshot', $response->getContent());
        $this->assertStringNotContainsString('actor_id', $response->getContent());
        $this->get('/public/clubhouse/other-club/data')->assertNotFound();
    }

    public function test_current_opening_spans_midnight_but_stops_at_a_closed_day(): void
    {
        $hours = app(ClubhouseHours::class);
        $hours->save($this->admin, $this->club, null, $this->opening(['weekdays' => [2],
            'intervals' => [['start' => '20:00', 'end' => '02:00', 'overnight' => true]]]));
        Date::setTestNow('2026-11-24 23:00:00 Europe/Zurich');
        $overview = app(ClubhouseService::class)->overview($this->club, 'web');
        $this->assertTrue($overview['open_now']);
        $this->assertSame('2026-11-25T02:00:00+01:00', $overview['open_until']);
        $hours->saveException($this->admin, $this->club, ['date' => '2026-11-25', 'closed' => true,
            'intervals' => [], 'revision' => 0]);
        $this->assertSame('2026-11-25T00:00:00+01:00', app(ClubhouseService::class)->overview($this->club, 'web')['open_until']);
    }

    public function test_management_paginates_and_searches_literal_values_without_disclosing_private_locations(): void
    {
        $menus = app(ClubhouseMenus::class);
        for ($i = 0; $i < 27; $i++) {
            $menus->save($this->admin, $this->club, null, $this->menu(['title_de' => $i === 0 ? '100%_menu' : 'Synthetic '.$i]));
        }
        $service = app(ClubhouseService::class);
        $first = $service->management($this->admin, $this->club);
        $second = $service->management($this->admin, $this->club, ['page' => 2]);
        $this->assertCount(25, $first['menus']);
        $this->assertTrue($first['pagination']['has_more']);
        $this->assertCount(2, $second['menus']);
        $this->assertFalse($second['pagination']['has_more']);
        $this->assertCount(1, $service->management($this->admin, $this->club, ['search' => '%_'])['menus']);
    }

    public function test_inactive_accounts_and_impersonation_cannot_mutate_clubhouse_data(): void
    {
        $this->grant();
        request()->attributes->set('clubunio_impersonation', (object) ['tenant_id' => 71, 'target_id' => 2]);
        $access = app(ClubhouseAccess::class);
        $this->assertTrue($access->allows($this->trainer, $this->club, 'view'));
        $this->assertFalse($access->allows($this->trainer, $this->club, 'hours.write'));
        $this->assertFalse($access->administrator($this->admin, $this->club));
        request()->attributes->remove('clubunio_impersonation');
        DB::connection('clubunio')->table('clubora.accounts')->where('id', 2)->update(['active' => false]);
        $this->assertFalse($access->allows($this->trainer, $this->club, 'view'));
        $this->assertFalse($access->capabilities($this->trainer, $this->club)['hours_write']);
    }

    public function test_openapi_lists_mobile_mutations_and_anonymous_calls_require_authentication(): void
    {
        $spec = app(MobileClubhouseDocumentation::class)->specification();
        $root = '/api/mobile/v1/clubs/{tenant}/clubhouse';
        $this->assertArrayHasKey('patch', $spec['paths'][$root.'/openings/{opening}']);
        $this->assertArrayHasKey('post', $spec['paths'][$root.'/menus/{menu}/{operation}']);
        $this->assertArrayHasKey('delete', $spec['paths'][$root.'/menus/{menu}/pdf']);
        $this->assertSame([], $spec['paths']['/public/clubhouse/{club}/data']['get']['security']);
        config(['clubunio-mobile.enabled' => true]);
        $this->getJson('/api/mobile/v1/clubs/71/clubhouse')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/clubs/71/clubhouse/menus', $this->menu())->assertUnauthorized();
    }
}
