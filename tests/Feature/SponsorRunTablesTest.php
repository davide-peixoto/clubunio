<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\SponsorRunEvent;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\Components\SponsorRunTable;
use App\ClubUnio\Pages\SponsorRunBilling;
use App\ClubUnio\Pages\SponsorRuns;
use App\ClubUnio\Services\AccessProfileService;
use App\ClubUnio\Services\SponsorRunBillingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SponsorRunTablesTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $event;

    private int $participant;

    private int $team;

    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'Synthetic table club', 'slug' => 'table-'.Str::random(16)]);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(24), 'display_name' => 'Table admin', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $season = $this->insert('seasons', ['name' => 'Table season']);
        $team = $this->insert('teams', ['name' => 'Table team']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Table team']);
        $this->insert('sponsor_run_statuses', ['code' => 'open', 'name' => 'Offen']);
        $this->insert('sponsor_run_statuses', ['code' => 'closed', 'name' => 'Geschlossen']);
        $this->event = $this->insert('sponsor_run_events', ['season_id' => $season, 'title' => 'Table run', 'place' => 'Test',
            'event_date' => '2032-05-14', 'registration_deadline' => '2032-05-14', 'status_code' => 'open', 'created_by' => $this->actor->id]);
        $this->participant = $this->insert('sponsor_run_participants', ['event_id' => $this->event, 'team_season_id' => $this->team,
            'member_id' => $this->insert('members', ['first_name' => 'Table', 'last_name' => 'Runner'])]);
        $pledge = $this->pledge('Table sponsor');
        $this->invoice = $this->insert('sponsor_run_invoices', ['event_id' => $this->event, 'pledge_id' => $pledge,
            'reference' => (string) Str::uuid(), 'connection_hash' => str_repeat('a', 64), 'configuration_hash' => str_repeat('b', 64),
            'snapshot_encrypted' => Crypt::encryptString('{}'), 'total_cents' => 1250, 'created_by' => $this->actor->id]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubunio.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $data);
    }

    private function pledge(string $name): int
    {
        return $this->insert('sponsor_run_pledges', ['event_id' => $this->event, 'participant_id' => $this->participant,
            'sponsor_name' => $name, 'payment_mode' => 'invoice', 'amount_type' => 'fixed', 'amount_cents' => 1250,
            'recipient_type' => 'person', 'recipient_encrypted' => Crypt::encryptString('{}'), 'request_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'created_by' => $this->actor->id]);
    }

    public function test_event_table_search_filters_sort_pagination_and_detail_action(): void
    {
        $event = SponsorRunEvent::findOrFail($this->event);
        for ($i = 0; $i < 11; $i++) {
            $copy = $event->replicate();
            $copy->forceFill(['title' => sprintf('Other run %02d', $i), 'status_code' => 'closed'])->save();
        }
        $page = Livewire::test(SponsorRuns::class)->assertSuccessful()->set('tableRecordsPerPage', 10);
        $this->assertSame(12, $page->instance()->getTableRecords()->total());
        $page->call('gotoPage', 2);
        $this->assertCount(2, $page->instance()->getTableRecords()->items());
        $page->searchTable('Table run')->assertCountTableRecords(1)
            ->filterTable('status_code', 'closed')->assertCountTableRecords(0)
            ->filterTable('status_code', null)->callTableAction('view', $event)->assertSet('eventId', $event->id)->assertSee('Teilnehmer');
        $page->searchTable('')->sortTable('title', 'asc');
        $this->assertSame('Other run 00', $page->instance()->getTableRecords()->first()->title);
    }

    public function test_participant_and_pledge_tables_use_independent_native_controls(): void
    {
        $participants = Livewire::test(SponsorRunTable::class, ['kind' => 'participants', 'eventId' => $this->event])
            ->assertSuccessful()->assertSee('Table Runner')->filterTable('team_season_id', $this->team)->assertCountTableRecords(1)
            ->searchTable('missing')->assertCountTableRecords(0)->searchTable('Table Runner')->assertCountTableRecords(1)
            ->callTableAction('view', (string) $this->participant)
            ->assertDispatched('sponsor-run-participant-selected', id: $this->participant);
        $pledges = Livewire::test(SponsorRunTable::class, ['kind' => 'pledges', 'eventId' => $this->event, 'participantId' => $this->participant])
            ->assertSuccessful()->assertSee('CHF 12.50')->assertCountTableRecords(1)
            ->filterTable('payment_mode', 'collective')->assertCountTableRecords(0)
            ->filterTable('payment_mode', 'invoice')->searchTable('Table sponsor')->assertCountTableRecords(1);
        $this->assertSame('Table Runner', $participants->get('tableSearch'));
        for ($i = 0; $i < 11; $i++) {
            $this->pledge('Extra sponsor '.$i);
        }
        $pledges->searchTable('')->set('tableRecordsPerPage', 10)->call('gotoPage', 2);
        $this->assertCount(2, $pledges->instance()->getTableRecords()->items());
        $pledges->searchTable('Table sponsor')->assertCountTableRecords(1);
        $pledges->set('tableRecordsPerPage', 999999);
        $this->assertSame(20, $pledges->instance()->getTableRecords()->perPage());
        Livewire::test(SponsorRunTable::class, ['kind' => 'helpers', 'eventId' => $this->event])->assertSuccessful()->assertCountTableRecords(0);
        Livewire::test(SponsorRunTable::class, ['kind' => 'outbox', 'eventId' => $this->event])->assertSuccessful()->assertCountTableRecords(0);
    }

    public function test_billing_filters_search_chf_and_native_confirmed_action_use_service(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->insert('sponsor_run_invoices', ['event_id' => $this->event, 'pledge_id' => $this->pledge('Extra billed sponsor '.$i),
                'reference' => (string) Str::uuid(), 'connection_hash' => str_repeat('a', 64), 'configuration_hash' => str_repeat('b', 64),
                'snapshot_encrypted' => Crypt::encryptString('{}'), 'total_cents' => 500, 'state' => 'cancelled', 'created_by' => $this->actor->id]);
        }
        $service = $this->partialMock(SponsorRunBillingService::class);
        $service->shouldReceive('execute')->once()->withArgs(fn ($actor, $tenant, $id, $revision, $operation, $data) => $actor->id === $this->actor->id && $tenant->id === $this->tenant->id && $id === $this->invoice
            && $revision === 1 && $operation === 'link-contact' && (int) $data['contact_id'] === 123)->andReturn((object) []);
        $page = Livewire::withQueryParams(['event' => $this->event])->test(SponsorRunBilling::class)
            ->assertSuccessful()->assertSee('CHF 12.50')->assertCountTableRecords(12)->set('tableRecordsPerPage', 10)->call('gotoPage', 2);
        $this->assertCount(2, $page->instance()->getTableRecords()->items());
        $page
            ->searchTable('missing')->assertCountTableRecords(0)->searchTable('Table sponsor')->assertCountTableRecords(1)
            ->filterTable('state', 'paid')->assertCountTableRecords(0)->filterTable('state', 'prepared')->assertCountTableRecords(1)
            ->callTableAction('link-contact', (string) $this->invoice, data: ['revision' => 1, 'contact_id' => 123])->assertHasNoActionErrors();
        $page->filterTable('event_id', 999999999)->assertCountTableRecords(0)->filterTable('event_id', null)->assertCountTableRecords(1);
        $page->set('tableRecordsPerPage', 'all');
        $this->assertSame(20, $page->instance()->getTableRecords()->perPage());
        $page->set('tableRecordsPerPage', 999999);
        $this->assertSame(20, $page->instance()->getTableRecords()->perPage());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_child_tables_recheck_tenant_and_revoked_access(): void
    {
        $foreign = Tenant::create(['name' => 'Foreign table club', 'slug' => 'foreign-'.Str::random(12)]);
        $season = $this->db->table('clubunio.seasons')->insertGetId(['tenant_id' => $foreign->id, 'name' => 'Foreign season']);
        $this->db->table('clubunio.sponsor_run_statuses')->insert(['tenant_id' => $foreign->id, 'code' => 'open', 'name' => 'Offen']);
        $id = $this->db->table('clubunio.sponsor_run_events')->insertGetId(['tenant_id' => $foreign->id, 'season_id' => $season,
            'title' => 'Foreign secret run', 'place' => 'Test', 'event_date' => '2032-05-14', 'registration_deadline' => '2032-05-14', 'status_code' => 'open', 'created_by' => $this->actor->id]);
        Livewire::test(SponsorRuns::class)->assertDontSee('Foreign secret run')->call('openEvent', $id)->assertNotFound();
        Livewire::test(SponsorRunTable::class, ['kind' => 'participants', 'eventId' => $id])->assertNotFound();
        $table = Livewire::test(SponsorRunTable::class, ['kind' => 'participants', 'eventId' => $this->event]);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->delete();
        $table->searchTable('Table')->assertForbidden();
    }

    public function test_read_only_sponsor_profile_can_read_details_but_cannot_mutate(): void
    {
        $profile = app(AccessProfileService::class)->save($this->actor, $this->tenant, null, [
            'name' => 'Table reader', 'active' => true, 'permissions' => ['sponsor_runs.view'], 'reason' => 'Synthetic table test',
        ]);
        $reader = new Account;
        $reader->forceFill(['username' => Str::random(24), 'display_name' => 'Table reader', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $reader->id, 'role' => 'profile', 'access_profile_id' => $profile->id]);
        $mounted = Livewire::test(SponsorRuns::class)->call('openEvent', $this->event)->call('openParticipant', $this->participant)
            ->mountAction('operation')->assertActionMounted('operation');
        $this->actingAs($reader, 'clubunio');
        Livewire::test(SponsorRuns::class)->assertSuccessful()->assertSee('Table run')
            ->call('openEvent', $this->event)->assertSet('eventId', $this->event)
            ->call('openParticipant', $this->participant)->assertSet('participantId', $this->participant)
            ->assertSee('CHF 12.50')->assertActionHidden('newEvent')->assertActionHidden('pledge');
        Livewire::test(SponsorRunTable::class, ['kind' => 'participants', 'eventId' => $this->event])
            ->assertSuccessful()->searchTable('Table Runner')->assertCountTableRecords(1)
            ->callTableAction('view', (string) $this->participant)
            ->assertDispatched('sponsor-run-participant-selected', id: $this->participant);
        Livewire::test(SponsorRuns::class)->callTableAction('view', $this->event)
            ->assertSet('eventId', $this->event)->assertActionHidden('newEvent');
        $mounted->call('callMountedAction')->assertForbidden();
        Livewire::test(SponsorRunTable::class, ['kind' => 'outbox', 'eventId' => $this->event])->assertForbidden();
        Livewire::test(SponsorRunBilling::class)->assertForbidden();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
