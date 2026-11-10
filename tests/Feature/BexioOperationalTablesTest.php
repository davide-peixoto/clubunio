<?php

namespace Tests\Feature;

use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioDispatchTrials;
use App\ClubUnio\Pages\BexioSandbox;
use App\ClubUnio\Services\BexioDispatchTrialService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BexioOperationalTablesTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private int $member;

    private int $released;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Rolled-back database fixture opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'operational-'.Str::random(16), 'name' => 'Synthetic operational table']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic admin', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'admin']);
        $this->member = $this->insert('members', ['first_name' => 'Table', 'last_name' => 'Member']);
        $this->released = $this->insert('invoice_workflow_statuses', ['code' => 'released', 'name' => 'Freigegeben']);
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
        return $this->db->table('clubunio.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    private function invoice(array $data = []): int
    {
        return $this->insert('invoices', $data + ['member_id' => $this->member, 'workflow_status_id' => $this->released]);
    }

    private function trial(int $invoice): int
    {
        return $this->insert('bexio_dispatch_trials', [
            'actor_id' => $this->actor->id, 'object_type' => 'invoice', 'object_id' => $invoice,
            'reference' => (string) Str::uuid(), 'fingerprint' => Str::random(64),
            'state' => 'ready', 'total_cents' => 2500, 'line_count' => 1,
        ]);
    }

    private function dispatch(int $invoice, array $data = []): int
    {
        return $this->insert('bexio_sandbox_dispatches', $data + [
            'actor_id' => $this->actor->id, 'kind' => 'invoice', 'invoice_id' => $invoice,
            'source_trial_id' => $this->trial($invoice), 'reference' => (string) Str::uuid(),
            'connection_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64),
            'payload' => '{}', 'state' => 'ready', 'expected_cents' => 2500,
        ]);
    }

    public function test_trial_document_table_search_sort_filter_and_pagination_never_call_provider(): void
    {
        $ids = [];
        for ($i = 0; $i < 17; $i++) {
            $ids[] = $this->invoice();
        }
        $this->trial($ids[0]);
        $this->invoice(['active' => false]);
        $this->invoice(['bexio_invoice_id' => 'already-linked']);
        $this->invoice(['legacy_id' => 123456]);
        $draft = $this->insert('invoice_workflow_statuses', ['code' => 'draft', 'name' => 'Entwurf']);
        $this->invoice(['workflow_status_id' => $draft]);
        $page = Livewire::test(BexioDispatchTrials::class)->assertSuccessful()->assertCountTableRecords(17);
        $this->assertCount(15, $page->instance()->getTableRecords()->items());
        $page->call('gotoPage', 2);
        $this->assertCount(2, $page->instance()->getTableRecords()->items());
        $page->searchTable('Table Member')->assertCountTableRecords(17)
            ->searchTable('not-present')->assertCountTableRecords(0)
            ->searchTable('')->filterTable('trial', 'checked')->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([Invoice::findOrFail($ids[0])])
            ->filterTable('trial', 'unchecked')->assertCountTableRecords(16)
            ->filterTable('trial', null)->sortTable('id', 'asc');
        $this->assertSame($ids[0], $page->instance()->getTableRecords()->first()->id);
        $page->sortTable('member.display_name', 'desc')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_trial_type_switch_and_sandbox_binding_preserve_document_eligibility(): void
    {
        $invoice = $this->invoice();
        $otherMember = $this->insert('members', ['first_name' => 'Outside', 'last_name' => 'Binding']);
        $this->invoice(['member_id' => $otherMember]);
        $status = $this->insert('expense_statuses', ['code' => 'Z', 'name' => 'Freigegeben']);
        $expense = $this->insert('expenses', ['member_id' => $this->member, 'status_id' => $status]);
        $this->insert('expenses', ['member_id' => $otherMember, 'status_id' => $status]);
        $this->db->table('clubunio.bexio_sandbox_fixtures')->insert([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member, 'parent_id' => $this->member, 'supplier_id' => $this->member,
            'invoice_id' => $invoice, 'remote_invoice_id' => 1, 'remote_contact_id' => 2,
            'connection_hash' => str_repeat('a', 64),
        ]);
        Livewire::test(BexioDispatchTrials::class)->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([Invoice::findOrFail($invoice)])
            ->searchTable('missing')->assertCountTableRecords(0)
            ->set('type', 'expense')->assertCountTableRecords(0)
            ->searchTable('')->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([Expense::findOrFail($expense)]);
        Http::assertNothingSent();
    }

    public function test_sandbox_operational_table_has_native_search_filters_sort_and_pagination(): void
    {
        $dispatches = [];
        for ($i = 0; $i < 17; $i++) {
            $dispatches[] = $this->dispatch($this->invoice(), ['state' => $i === 0 ? 'uncertain' : 'ready', 'remote_id' => 'table-remote-'.$i]);
        }
        $page = Livewire::test(BexioSandbox::class)->assertSuccessful()->assertSee('fi-ta', false)->assertCountTableRecords(17);
        $this->assertCount(15, $page->instance()->getTableRecords()->items());
        $page->call('gotoPage', 2);
        $this->assertCount(2, $page->instance()->getTableRecords()->items());
        $page->searchTable('table-remote-0')->assertCountTableRecords(1)
            ->searchTable('')->filterTable('state', 'uncertain')->assertCountTableRecords(1)
            ->filterTable('kind', 'expense')->assertCountTableRecords(0)
            ->filterTable('kind', 'invoice')->assertCountTableRecords(1)
            ->filterTable('state', null)->filterTable('source', 'fixture')->assertCountTableRecords(0)
            ->filterTable('source', 'trial')->assertCountTableRecords(17)->sortTable('document', 'desc');
        $this->assertSame(end($dispatches), $page->instance()->getTableRecords()->first()['id']);
        $page->sortTable('state', 'asc')->sortTable('remote_id', 'desc')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_expense_search_finds_displayed_legacy_number_without_including_unreleased_documents(): void
    {
        $status = $this->insert('expense_statuses', ['code' => 'Z', 'name' => 'Freigegeben']);
        $open = $this->insert('expense_statuses', ['code' => 'O', 'name' => 'Offen']);
        $expense = $this->insert('expenses', ['legacy_id' => 812345, 'member_id' => $this->member, 'status_id' => $status]);
        $this->insert('expenses', ['legacy_id' => 812346, 'member_id' => $this->member, 'status_id' => $open]);
        Livewire::test(BexioDispatchTrials::class)->set('type', 'expense')->searchTable('81234')->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([Expense::findOrFail($expense)]);
        Http::assertNothingSent();
    }

    public function test_sandbox_row_controls_keep_state_guards_and_confirmation(): void
    {
        $ready = $this->dispatch($this->invoice());
        $uncertain = $this->dispatch($this->invoice(), ['state' => 'uncertain']);
        $page = Livewire::test(BexioSandbox::class)->assertSuccessful()
            ->assertSeeHtml('wire:click="send('.$ready.')"')
            ->assertSeeHtml('wire:confirm="Den synthetischen Testbeleg')
            ->assertDontSeeHtml('wire:click="send('.$uncertain.')"')
            ->assertSeeHtml('wire:click="reconcile('.$uncertain.')"');
        $record = $page->instance()->getTableRecords()->first();
        $this->assertArrayNotHasKey('payload', $record);
        $this->assertArrayNotHasKey('connection_hash', $record);
        Http::assertNothingSent();
    }

    public function test_role_revocation_blocks_table_refresh_without_provider_calls(): void
    {
        $this->dispatch($this->invoice());
        $page = Livewire::test(BexioSandbox::class)->assertSuccessful();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $page->searchTable('changed')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_profile_document_action_keeps_existing_method_guards(): void
    {
        $invoice = $this->invoice();
        $profile = new AccessProfile;
        $profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic sender', 'active' => true,
            'permissions' => ['bexio.view', 'invoices.view', 'invoices.send'], 'revision' => 1])->save();
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)
            ->update(['role' => 'profile', 'access_profile_id' => $profile->id]);
        $this->mock(BexioDispatchTrialService::class, function ($mock) use ($invoice) {
            $mock->shouldReceive('preview')->once()->withArgs(fn ($actor, $tenant, $type, $id) => $actor->id === $this->actor->id
                && $tenant->id === $this->tenant->id && $type === 'invoice' && $id === $invoice)->andReturn(1);
        });
        $page = Livewire::test(BexioDispatchTrials::class)->assertSuccessful()->assertCountTableRecords(1);
        $action = $page->instance()->getTable()->getAction('check')->record(Invoice::findOrFail($invoice));
        $this->assertSame('$wire.check('.$invoice.')', $action->getCustomAlpineClickHandler());
        $this->assertTrue($action->isVisible());
        $page->call('check', $invoice)->assertHasNoErrors();
        $profile->forceFill(['permissions' => ['bexio.view', 'invoices.view']])->save();
        $page->call('check', $invoice)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_native_search_cannot_escape_the_selected_tenant(): void
    {
        $local = $this->invoice();
        $this->dispatch($local, ['remote_id' => 'shared-search-reference']);
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(16), 'name' => 'Synthetic foreign club']);
        $this->insert('tenant_accounts', ['tenant_id' => $foreign->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        $member = $this->insert('members', ['tenant_id' => $foreign->id, 'first_name' => 'Table', 'last_name' => 'Member']);
        $released = $this->insert('invoice_workflow_statuses', ['tenant_id' => $foreign->id, 'code' => 'released', 'name' => 'Freigegeben']);
        $invoice = $this->insert('invoices', ['tenant_id' => $foreign->id, 'member_id' => $member, 'workflow_status_id' => $released]);
        $this->insert('bexio_sandbox_dispatches', [
            'tenant_id' => $foreign->id, 'actor_id' => $this->actor->id, 'kind' => 'invoice', 'invoice_id' => $invoice,
            'reference' => (string) Str::uuid(), 'connection_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64),
            'payload' => '{}', 'state' => 'ready', 'remote_id' => 'shared-search-reference',
        ]);
        Livewire::test(BexioDispatchTrials::class)->searchTable('Table Member')->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([Invoice::findOrFail($local)])->assertCanNotSeeTableRecords([Invoice::findOrFail($invoice)]);
        Livewire::test(BexioSandbox::class)->searchTable('shared-search-reference')->assertCountTableRecords(1);
        Http::assertNothingSent();
    }
}
