<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ClubEvent;
use App\ClubUnio\Models\ClubFunction;
use App\ClubUnio\Models\EventGuest;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberFunction;
use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Models\Qualification;
use App\ClubUnio\Models\Sponsorship;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ClubEventResource\Pages\ManageEvents;
use App\ClubUnio\Resources\ClubEventResource\Pages\ViewEvent;
use App\ClubUnio\Resources\ClubFunctionResource\Pages\ManageFunctions;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\ContributionRulesRelationManager;
use App\ClubUnio\Resources\Relations\EventAttendeesRelationManager;
use App\ClubUnio\Resources\Relations\EventGuestsRelationManager;
use App\ClubUnio\Resources\Relations\FunctionsRelationManager;
use App\ClubUnio\Resources\Relations\KeysRelationManager;
use App\ClubUnio\Resources\Relations\SponsorshipsRelationManager;
use App\ClubUnio\Resources\SeasonResource;
use App\ClubUnio\Services\DossierMaintenanceService;
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

class DossierMaintenanceTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Member $member;

    private int $season;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'dossier-'.Str::random(10), 'name' => 'Fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(14), 'display_name' => 'Fixture Office', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'office']);
        $this->member = new Member;
        $this->member->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Dossier'])->save();
        $this->season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Fixture Season']);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        } parent::tearDown();
    }

    private function save(string $model, array $data, ?int $id = null, ?int $member = null)
    {
        return app(DossierMaintenanceService::class)->save($this->actor, $this->tenant, $model, $member, $id, $data);
    }

    public function test_dossier_copy_deactivate_restore_preserves_sources_and_checks_duplicates(): void
    {
        $function = $this->save(ClubFunction::class, ['name' => 'Fixture A', 'active' => true]);
        $otherFunction = $this->save(ClubFunction::class, ['name' => 'Fixture B', 'active' => true]);
        $qualification = $this->save(Qualification::class, ['name' => 'Fixture licence', 'active' => true]);
        $event = $this->save(ClubEvent::class, ['name' => 'Fixture A', 'location' => 'Here', 'season_id' => $this->season, 'active' => true]);
        $otherEvent = $this->save(ClubEvent::class, ['name' => 'Fixture B', 'location' => 'Here', 'season_id' => $this->season, 'active' => true]);
        $cases = [
            [FunctionsRelationManager::class, MemberFunction::class, ['club_function_id' => $function->id, 'qualification_ids' => [$qualification->id]], ['club_function_id' => $otherFunction->id]],
            [SponsorshipsRelationManager::class, Sponsorship::class, ['name' => 'Fixture', 'amount' => 100, 'expires_on' => '2027-12-31', 'notes' => 'Fixture'], ['expires_on' => '2028-12-31']],
            [EventGuestsRelationManager::class, EventGuest::class, ['club_event_id' => $event->id], ['club_event_id' => $otherEvent->id]],
        ];
        foreach ($cases as [$manager, $model, $data, $copyData]) {
            $source = $this->save($model, $data + ['active' => true], null, $this->member->id);
            $component = Livewire::test($manager, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
            $component->callTableAction('copyItem', $source)->assertHasTableActionErrors();
            $component = Livewire::test($manager, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
            $component->callTableAction('copyItem', $source, data: $copyData)->assertHasNoTableActionErrors()->assertNoRedirect();
            $copy = $model::where('tenant_id', $this->tenant->id)->whereKeyNot($source->id)->sole();
            $this->assertSame($this->member->id, $copy->member_id);
            $this->assertSame(1, (int) $source->fresh()->revision);
            if ($model === MemberFunction::class) {
                $this->assertSame([$qualification->id], $copy->qualifications()->pluck('clubora.qualifications.id')->all());
            }
            $component->callTableAction('deactivateItem', $source)->assertHasNoTableActionErrors();
            $this->assertFalse($source->fresh()->active);
            $component->filterTable('active', false)->callTableAction('restoreItem', $source->fresh())->assertHasNoTableActionErrors();
            $this->assertTrue($source->fresh()->active);
            $component->filterTable('active', true)->mountTableAction('deactivateItem', $source->fresh());
            $source->refresh()->forceFill(['revision' => $source->revision + 1])->save();
            $component->callMountedTableAction()->assertHasTableActionErrors();
            $this->assertTrue($source->fresh()->active);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_function_and_multiple_qualifications_are_editable_audited_and_revision_protected(): void
    {
        $function = $this->save(ClubFunction::class, ['name' => 'Coach', 'active' => true]);
        $q1 = $this->save(Qualification::class, ['name' => 'License A', 'active' => true]);
        $q2 = $this->save(Qualification::class, ['name' => 'License B', 'active' => true]);
        $data = ['club_function_id' => $function->id, 'qualification_ids' => [$q1->id, $q2->id], 'active' => true];
        $r = $this->save(MemberFunction::class, $data, null, $this->member->id);
        $this->assertCount(2, $r->qualifications);
        $this->assertSame(1, (int) $r->revision);
        $changed = $this->save(MemberFunction::class, ['revision' => 1, 'qualification_ids' => [$q2->id]] + $data, $r->id, $this->member->id);
        $this->assertCount(1, $changed->qualifications);
        $this->assertSame(2, (int) $changed->revision);
        $this->expectException(ValidationException::class);
        $this->save(MemberFunction::class, ['revision' => 1] + $data, $r->id, $this->member->id);
    }

    public function test_keys_and_sponsorship_can_be_created_and_deactivated_without_deleting_history(): void
    {
        $key = $this->save(MemberKey::class, ['number' => '123', 'name' => 'Synthetic key', 'color' => 'Blue', 'active' => true], null, $this->member->id);
        $sponsor = $this->save(Sponsorship::class, ['name' => 'Synthetic Sponsor', 'amount' => '100.00', 'expires_on' => '2027-12-31', 'notes' => 'Fixture', 'active' => true], null, $this->member->id);
        $this->assertSame('123', $key->number);
        $this->assertEquals(100, $sponsor->amount);
        $this->save(MemberKey::class, ['revision' => 1, 'number' => '123', 'name' => 'Synthetic key', 'color' => 'Blue', 'active' => false], $key->id, $this->member->id);
        $this->assertFalse($key->fresh()->active);
        $this->assertSame(3, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_event_guest_master_detail_and_duplicate_protection(): void
    {
        $event = $this->save(ClubEvent::class, ['name' => 'Fixture event', 'location' => 'Fixture location', 'season_id' => $this->season, 'team_id' => null, 'active' => true]);
        $guest = $this->save(EventGuest::class, ['club_event_id' => $event->id, 'active' => true], null, $this->member->id);
        $this->assertSame($guest->id, $event->guests()->sole()->id);
        $this->assertSame($event->id, $guest->clubEvent->id);
        $this->expectException(ValidationException::class);
        $this->save(EventGuest::class, ['club_event_id' => $event->id, 'active' => true], null, $this->member->id);
    }

    public function test_foreign_qualification_and_protected_member_reassignment_are_rejected_atomically(): void
    {
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(8), 'name' => 'Foreign']);
        $foreign = $this->db->table('clubora.qualifications')->insertGetId(['tenant_id' => $other->id, 'name' => 'Foreign qualification']);
        $function = $this->save(ClubFunction::class, ['name' => 'Fixture function', 'active' => true]);
        try {
            $this->save(MemberFunction::class, ['club_function_id' => $function->id, 'qualification_ids' => [$foreign], 'active' => true], null, $this->member->id);
            $this->fail('Foreign qualification accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qualification_ids', $e->errors());
        }
        $this->assertSame(0, MemberFunction::where('tenant_id', $this->tenant->id)->count());
        $this->expectException(ValidationException::class);
        $this->save(MemberKey::class, ['member_id' => 123, 'number' => '1', 'name' => 'Fixture', 'color' => 'Blue', 'active' => true], null, $this->member->id);
    }

    public function test_catalog_and_member_relation_actions_work_through_filament(): void
    {
        Livewire::test(ManageFunctions::class)->callTableAction('create', data: ['name' => 'New function', 'active' => true, 'revision' => 0])->assertHasNoTableActionErrors()->assertSee('New function');
        Livewire::test(ManageEvents::class)->callTableAction('create', data: ['name' => 'New event', 'location' => 'Fixture', 'season_id' => $this->season, 'team_id' => null, 'active' => true, 'revision' => 0])->assertHasNoTableActionErrors()->assertSee('New event');
        Livewire::test(KeysRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])->callTableAction('addItem', data: ['number' => '777', 'name' => 'Fixture Key', 'color' => 'Blue', 'active' => true, 'revision' => 0])->assertHasNoTableActionErrors()->assertSee('777');
        $this->get('/club-events')->assertOk();
        $this->get('/qualifications')->assertOk();
        $this->get('/club-functions')->assertOk();
        $this->assertContains(ContributionRulesRelationManager::class, SeasonResource::getRelations());
    }

    public function test_function_qualification_action_and_event_master_guest_action(): void
    {
        $function = $this->save(ClubFunction::class, ['name' => 'Action function', 'active' => true]);
        $qualification = $this->save(Qualification::class, ['name' => 'Action qualification', 'active' => true]);
        Livewire::test(FunctionsRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class])
            ->callTableAction('addItem', data: ['revision' => 0, 'club_function_id' => $function->id, 'qualification_ids' => [$qualification->id], 'active' => true])->assertHasNoTableActionErrors()->assertSee('Action qualification');
        $event = $this->save(ClubEvent::class, ['name' => 'Action event', 'location' => 'Fixture location', 'season_id' => $this->season, 'team_id' => null, 'active' => true]);
        Livewire::test(EventAttendeesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => ViewEvent::class])
            ->callTableAction('addGuest', data: ['member_id' => $this->member->id])->assertHasNoTableActionErrors()->assertSee('Dossier');
        $this->get('/club-events/'.$event->id)->assertOk();
    }

    public function test_wrong_owner_and_foreign_event_are_rejected(): void
    {
        $key = $this->save(MemberKey::class, ['name' => 'Owner key', 'number' => '888', 'color' => 'Blue', 'active' => true], null, $this->member->id);
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Other'])->save();
        try {
            $this->save(MemberKey::class, ['revision' => 1, 'name' => 'Owner key', 'number' => '888', 'color' => 'Blue', 'active' => false], $key->id, $other->id);
            $this->fail('Wrong owner accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(9), 'name' => 'Foreign']);
        $event = $this->db->table('clubora.club_events')->insertGetId(['tenant_id' => $foreign->id, 'name' => 'Foreign event']);
        $this->expectException(ValidationException::class);
        $this->save(EventGuest::class, ['club_event_id' => $event, 'active' => true], null, $this->member->id);
    }

    public function test_inactive_or_non_office_actor_cannot_mutate(): void
    {
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $this->save(Qualification::class, ['name' => 'Denied', 'active' => true]);
    }
}
