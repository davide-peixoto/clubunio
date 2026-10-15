<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Announcement;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\AnnouncementResource;
use App\ClubUnio\Resources\AnnouncementResource\Pages\ListAnnouncements;
use App\ClubUnio\Services\AnnouncementService;
use App\ClubUnio\Services\MessageTemplateService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $office;

    private Account $player;

    private Account $parent;

    private Member $child;

    private int $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'announce-'.Str::random(10), 'name' => 'Synthetic club']);
        foreach (['draft', 'published', 'withdrawn'] as $code) {
            $this->insert('announcement_statuses', ['code' => $code, 'name' => $code]);
        }
        $this->office = $this->account('office');
        $this->player = $this->account('member');
        $this->parent = $this->account('member');
        $this->child = new Member;
        $this->child->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Player', 'birth_date' => '2014-01-02'])->save();
        foreach ([[$this->player, 'self'], [$this->parent, 'parent']] as [$a,$kind]) {
            $this->insert('account_member_links', ['account_id' => $a->id, 'member_id' => $this->child->id, 'kind' => $kind, 'state' => 'approved', 'source' => 'test']);
        }
        $team = $this->insert('teams', ['name' => 'Synthetic team']);
        $season = $this->insert('seasons', ['name' => 'Synthetic season']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic team / season']);
        $this->insert('team_players', ['team_season_id' => $this->team, 'member_id' => $this->child->id]);
        app(MessageTemplateService::class)->seed($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->office, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $data);
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => strtolower(Str::random(20)), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function draft(string $target = 'all', array $ids = []): Announcement
    {
        return app(AnnouncementService::class)->save($this->office, $this->tenant, null, ['title' => 'Synthetic title', 'body' => "Synthetic body\nSecond line", 'target_type' => $target, 'target_ids' => $ids]);
    }

    private function publish(Announcement $r): int
    {
        $service = app(AnnouncementService::class);
        $preview = $service->prepare($this->office, $this->tenant, $r->id, $r->revision);

        return $service->publish($this->office, $this->tenant, $r->id, $r->revision, $preview['preview_token']);
    }

    public function test_all_targets_only_reachable_users_once_and_portal_reads_are_private(): void
    {
        $this->account('member'); // no approved identity, not reachable
        $inactive = $this->account('office');
        $inactive->forceFill(['active' => false])->save();
        $r = $this->draft();
        $this->assertSame(0, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, $this->publish($r));
        $this->assertSame('published', $r->fresh()->state);
        $this->assertSame(3, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->actingAs($this->player, 'portal')->get(route('clubunio.portal.announcements', $this->tenant->slug))->assertOk()->assertSee('Synthetic title');
        $this->get(route('clubunio.portal.announcement', [$this->tenant->slug, $r->id]))->assertOk()->assertSee('Second line');
        $this->actingAs($this->parent, 'portal')->get(route('clubunio.portal.notifications', $this->tenant->slug))->assertOk()->assertSee('Ankündigung öffnen');
        $later = $this->account('office');
        $this->actingAs($later, 'portal')->get(route('clubunio.portal.announcement', [$this->tenant->slug, $r->id]))->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        app(AnnouncementService::class)->publish($this->office, $this->tenant, $r->id, 1, (string) Str::uuid());
    }

    public function test_teams_include_player_parent_staff_and_lead_with_deduplication(): void
    {
        $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $this->child->id]);
        $this->insert('technical_leads', ['team_season_id' => $this->team, 'member_id' => $this->child->id]);
        $r = $this->draft('teams', [$this->team]);
        $this->assertSame(2, $this->publish($r));
        $this->assertSame(2, $r->fresh()->recipient_count);
        $this->assertFalse(app(AnnouncementService::class)->portal($this->office, $this->tenant)->whereKey($r->id)->exists());
    }

    public function test_roles_and_accounts_are_explicit_and_departed_children_excluded(): void
    {
        $r = $this->draft('roles', ['office']);
        $this->assertSame(1, $this->publish($r));
        $r = $this->draft('accounts', [$this->parent->id, $this->player->id]);
        $this->assertSame(2, $this->publish($r));
        $this->child->forceFill(['birth_date' => now('Europe/Zurich')->subYears(18)->toDateString()])->save();
        $r = $this->draft();
        $this->assertSame(2, $this->publish($r)); // Office and own player, no parent of adult.
        $this->child->forceFill(['exit_date' => now('Europe/Zurich')->toDateString()])->save();
        $r = $this->draft();
        $this->assertSame(1, $this->publish($r));
    }

    public function test_audience_or_template_drift_expiry_and_wrong_actor_cannot_publish(): void
    {
        $service = app(AnnouncementService::class);
        $r = $this->draft();
        $p = $service->prepare($this->office, $this->tenant, $r->id, 1);
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        try {
            $service->publish($this->office, $this->tenant, $r->id, 1, $p['preview_token']);
            $this->fail('Audience drift');
        } catch (ValidationException) {
            $this->assertSame('draft', $r->fresh()->state);
        }
        $p = $service->prepare($this->office, $this->tenant, $r->id, 1);
        $this->db->table('clubora.message_templates')->where('tenant_id', $this->tenant->id)->where('event_key', 'announcement.manual')->where('channel', 'push')->increment('revision');
        try {
            $service->publish($this->office, $this->tenant, $r->id, 1, $p['preview_token']);
            $this->fail('Template drift');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $p = $service->prepare($this->office, $this->tenant, $r->id, 1);
        $other = $this->account('office');
        try {
            $service->publish($other, $this->tenant, $r->id, 1, $p['preview_token']);
            $this->fail('Wrong actor');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $r->forceFill(['preview_expires_at' => now()->subMinute()])->save();
        $this->expectException(ValidationException::class);
        $service->publish($this->office, $this->tenant, $r->id, 1, $p['preview_token']);
    }

    public function test_withdrawal_retracts_portal_content_and_notification_but_keeps_history(): void
    {
        $r = $this->draft();
        $this->publish($r);
        app(AnnouncementService::class)->withdraw($this->office, $this->tenant, $r->id, 2);
        $this->assertSame('withdrawn', $r->fresh()->state);
        $this->assertSame('Synthetic title', $r->fresh()->rendered_title);
        $this->actingAs($this->player, 'portal')->get(route('clubunio.portal.announcement', [$this->tenant->slug, $r->id]))->assertNotFound();
        $this->get(route('clubunio.portal.notifications', $this->tenant->slug))->assertOk()->assertSee('Ankündigung zurückgezogen')->assertDontSee('Synthetic body');
        $this->assertSame(3, $this->db->table('clubora.announcement_recipients')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_invalid_foreign_nested_protected_and_stale_data_are_rejected(): void
    {
        foreach ([['target_type' => 'accounts', 'target_ids' => [99999999]], ['target_type' => 'teams', 'target_ids' => [99999999]],
            ['target_type' => 'roles', 'target_ids' => [['office']]], ['state' => 'published'], ['body' => '<script>bad</script>']] as $bad) {
            try {
                app(AnnouncementService::class)->save($this->office, $this->tenant, null, $bad + ['title' => 'Test', 'body' => 'Test', 'target_type' => 'all', 'target_ids' => []]);
                $this->fail('Invalid input');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $r = $this->draft();
        app(AnnouncementService::class)->save($this->office, $this->tenant, $r->id, ['revision' => 1, 'title' => 'Changed', 'body' => 'Body', 'target_type' => 'all', 'target_ids' => []]);
        $this->expectException(ValidationException::class);
        app(AnnouncementService::class)->prepare($this->office, $this->tenant, $r->id, 1);
    }

    public function test_trainer_cannot_author_and_office_forms_preview_and_publish_are_functional(): void
    {
        $page = Livewire::test(ListAnnouncements::class)->assertSuccessful();
        $page->callTableAction('newAnnouncement', data: ['revision' => 0, 'title' => 'UI draft', 'body' => 'UI body', 'target_type' => 'all', 'target_ids' => []])->assertHasNoActionErrors();
        $r = Announcement::where('tenant_id', $this->tenant->id)->sole();
        $page = Livewire::test(ListAnnouncements::class)->assertCanSeeTableRecords([$r])->mountTableAction('publish', $r)->assertSet('mountedActions.0.name', 'publish');
        $this->assertStringContainsString('UI body', $page->instance()->getMountedAction()->getModalContent()->render());
        $page->setTableActionData(['confirmed' => true])->callMountedTableAction()->assertHasNoActionErrors();
        $this->assertSame('published', $r->fresh()->state);
        $trainer = $this->account('trainer');
        $this->expectException(HttpException::class);
        app(AnnouncementService::class)->save($trainer, $this->tenant, null, ['title' => 'Bad', 'body' => 'Bad', 'target_type' => 'all', 'target_ids' => []]);
    }

    public function test_disabled_template_empty_audience_and_edited_preview_cannot_publish(): void
    {
        $service = app(AnnouncementService::class);
        $r = $this->draft('accounts', [$this->account('member')->id]);
        try {
            $service->prepare($this->office, $this->tenant, $r->id, 1);
            $this->fail('Unreachable audience');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $r = $this->draft();
        $p = $service->prepare($this->office, $this->tenant, $r->id, 1);
        $service->save($this->office, $this->tenant, $r->id, ['revision' => 1, 'title' => 'Updated', 'body' => 'Body', 'target_type' => 'all', 'target_ids' => []]);
        try {
            $service->publish($this->office, $this->tenant, $r->id, 2, $p['preview_token']);
            $this->fail('Old preview');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->db->table('clubora.message_templates')->where('tenant_id', $this->tenant->id)->where('event_key', 'announcement.manual')->where('channel', 'push')->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $service->prepare($this->office, $this->tenant, $r->id, 2);
    }

    public function test_published_snapshot_copy_and_read_count_and_foreign_scope(): void
    {
        $r = $this->draft();
        $this->publish($r);
        $notice = $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->where('announcement_id', $r->id)->where('account_id', $this->player->id)->sole();
        $this->actingAs($this->player, 'portal')->post(route('clubunio.portal.notifications.read', [$this->tenant->slug, $notice->id]))->assertRedirect();
        $this->assertSame(1, (int) AnnouncementResource::getEloquentQuery()->findOrFail($r->id)->read_count);
        $copy = app(AnnouncementService::class)->duplicate($this->office, $this->tenant, $r->id);
        $this->assertSame('draft', $copy->state);
        $this->assertNull($copy->published_at);
        $this->assertSame(3, $this->db->table('clubora.notifications')->where('tenant_id', $this->tenant->id)->count());
        $this->db->table('clubora.message_templates')->where('tenant_id', $this->tenant->id)->where('event_key', 'announcement.manual')->update(['title' => 'Changed template']);
        $this->get(route('clubunio.portal.announcement', [$this->tenant->slug, $r->id]))->assertOk()->assertSee('Synthetic title')->assertDontSee('Changed template');
        $foreign = Tenant::create(['name' => 'Foreign', 'slug' => 'foreign-'.Str::random(10)]);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $foreign->id, 'account_id' => $this->office->id, 'role' => 'office']);
        $this->expectException(ModelNotFoundException::class);
        app(AnnouncementService::class)->duplicate($this->office, $foreign, $r->id);
    }
}
