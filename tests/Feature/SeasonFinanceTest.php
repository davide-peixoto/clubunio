<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ExpenseResource\Pages\ViewExpense;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\Relations\ExpenseLinesRelationManager;
use App\ClubUnio\Resources\Relations\ExpensesRelationManager;
use App\ClubUnio\Resources\Relations\InvoiceLinesRelationManager;
use App\ClubUnio\Resources\Relations\InvoicesRelationManager;
use App\ClubUnio\Resources\SeasonResource\Pages\ViewSeason;
use App\ClubUnio\Resources\TeamResource\Pages\ViewTeam;
use App\ClubUnio\Resources\TeamSeasonResource\Pages\ViewTeamSeason;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SeasonFinanceTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private Season $season;

    private Member $member;

    private int $category;

    private array $expenseData;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'season-finance-'.Str::random(12), 'name' => 'Fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Fixture', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $this->actor->id, 'role' => 'office']);
        $this->season = Season::findOrFail($this->insert('seasons', ['name' => 'Fixture Season']));
        $this->member = Member::findOrFail($this->insert('members', ['first_name' => 'Fixture', 'last_name' => 'Member']));
        $this->category = $this->insert('invoice_categories', ['name' => 'Fixture', 'code' => 'fixture']);
        foreach (['draft', 'released', 'cancelled'] as $code) {
            $this->insert('invoice_workflow_statuses', ['name' => $code, 'code' => $code]);
        }
        $this->insert('invoice_line_statuses', ['name' => 'Pendent', 'code' => 'P']);
        foreach (['O', 'F', 'Z', 'R', 'B'] as $code) {
            $this->insert('expense_statuses', ['name' => $code, 'code' => $code]);
        }
        $team = $this->insert('teams', ['name' => 'Fixture Team']);
        $this->expenseData = [
            'member_id' => $this->member->id,
            'team_season_id' => $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $this->season->id, 'label' => 'Fixture Team / Season']),
            'round_id' => $this->insert('expense_rounds', ['name' => 'Fixture Round', 'code' => 'fixture']),
            'bank_account_id' => $this->insert('member_bank_accounts', ['member_id' => $this->member->id, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]),
            'notes' => 'Fixture',
        ];
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
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]);
    }

    public function test_expense_button_checks_are_shared_only_inside_read_context(): void
    {
        $service = app(ExpenseService::class);
        $expense = $service->save($this->actor, $this->tenant, null, $this->expenseData);
        $manager = Livewire::test(ExpenseLinesRelationManager::class,
            ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class]);
        $action = $manager->instance()->getTable()->getAction('editLine');
        $request = request();
        $method = $request->method();
        $request->setMethod('GET');
        $request->setUserResolver(fn ($guard = null) => $this->actor);
        try {
            app(BackofficeReadContext::class)->during($request, function () use ($action) {
                $this->db->enableQueryLog();
                $this->db->flushQueryLog();
                for ($i = 0; $i < 15; $i++) {
                    $action->clearVisibilityCache();
                    $this->assertTrue($action->isVisible());
                }
                $this->assertLessThanOrEqual(3, count($this->db->getQueryLog()));
                $this->db->disableQueryLog();
            });
        } finally {
            $request->setMethod($method);
        }
        $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $action->clearVisibilityCache();
        $this->assertFalse($action->isVisible());
        $this->assertFalse($service->editable($this->actor, $this->tenant, $expense->fresh()));
    }

    private function manager(bool $invoice = true)
    {
        return Livewire::test($invoice ? InvoicesRelationManager::class : ExpensesRelationManager::class,
            ['ownerRecord' => $this->season, 'pageClass' => ViewSeason::class]);
    }

    private function invoice(): Invoice
    {
        $this->manager()->callTableAction('createFinance', data: ['member_id' => $this->member->id,
            'invoice_category_id' => $this->category, 'tax_mode' => 'exempt', 'notes' => 'Fixture'])->assertHasNoTableActionErrors();

        return Invoice::where('tenant_id', $this->tenant->id)->sole();
    }

    public function test_member_finance_is_pinned_and_positions_stay_in_dossier(): void
    {
        $manager = Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
        $manager->callTableAction('createFinance', data: ['invoice_category_id' => $this->category, 'tax_mode' => 'exempt', 'season_id' => $this->season->id])->assertHasNoTableActionErrors();
        $invoice = Invoice::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame($this->member->id, $invoice->member_id);
        $manager->callTableAction('editFinance', $invoice, data: ['notes' => 'Member changed'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $lines = Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class, 'financeContextType' => Member::class, 'financeContextId' => $this->member->id]);
        $lines->callTableAction('addLine', data: ['description' => 'Fixture', 'gross_amount' => '20', 'discount' => '0'])->assertHasNoTableActionErrors()->assertNoRedirect()->assertDispatched('season-finance-updated');
        $other = $this->insert('members', ['first_name' => 'Other']);
        $invoice->forceFill(['member_id' => $other])->save();
        $lines->call('$refresh')->assertForbidden();
        $manager->call('saveSeasonFinance', $invoice->id, ['revision' => $invoice->fresh()->revision])->assertStatus(404);
        $expenses = Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $this->member, 'pageClass' => ViewMember::class]);
        $expenses->call('saveSeasonFinance', null, array_replace($this->expenseData, ['member_id' => $other]))->assertSuccessful();
        $this->assertSame($this->member->id, Expense::where('tenant_id', $this->tenant->id)->sole()->member_id);
    }

    public function test_team_finance_uses_explicit_invoice_links_and_scoped_expenses(): void
    {
        $invoice = $this->invoice();
        app(InvoiceWorkflowService::class)->change($this->actor, $this->tenant, $invoice->id, $invoice->revision, 'line', ['description' => 'Fixture', 'gross_amount' => 20, 'discount' => 0]);
        $teamSeason = TeamSeason::findOrFail($this->expenseData['team_season_id']);
        $this->assertFalse($teamSeason->invoices()->exists());
        $this->insert('team_players', ['team_season_id' => $teamSeason->id, 'member_id' => $this->member->id, 'invoice_line_id' => $invoice->lines()->sole()->id]);
        foreach ([$teamSeason, $teamSeason->team] as $owner) {
            $this->assertSame($invoice->id, $owner->invoices()->sole()->id);
            $page = $owner instanceof TeamSeason ? ViewTeamSeason::class : ViewTeam::class;
            $manager = Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page]);
            $manager->assertTableActionHidden('createFinance')->callTableAction('editFinance', $invoice->fresh(), data: ['notes' => 'Team changed'])->assertHasNoTableActionErrors()->assertNoRedirect();
            $expenses = Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page]);
            $expenses->callTableAction('createFinance', data: $this->expenseData)->assertHasNoTableActionErrors();
            $expense = $owner->expenses()->latest('id')->firstOrFail();
            $expenses->callTableAction('editFinance', $expense, data: ['notes' => 'Team expense changed'])->assertHasNoTableActionErrors();
            $lines = Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class, 'financeContextType' => $owner::class, 'financeContextId' => $owner->id]);
            $lines->assertSuccessful();
        }
        $otherTeam = Team::findOrFail($this->insert('teams', ['name' => 'Other Team']));
        $otherSeasonTeam = $this->insert('team_seasons', ['team_id' => $otherTeam->id, 'season_id' => $this->season->id, 'label' => 'Other Team']);
        Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $teamSeason->team, 'pageClass' => ViewTeam::class])
            ->call('saveSeasonFinance', null, array_replace($this->expenseData, ['team_season_id' => $otherSeasonTeam]))->assertStatus(404);
        Livewire::test(InvoicesRelationManager::class, ['ownerRecord' => $teamSeason, 'pageClass' => ViewTeamSeason::class])
            ->call('saveSeasonFinance', null, ['member_id' => $this->member->id])->assertForbidden();
        $this->db->table('clubora.team_players')->where('tenant_id', $this->tenant->id)->update(['invoice_line_id' => null]);
        $lines->call('$refresh')->assertForbidden();
    }

    public function test_invoice_header_and_positions_remain_inside_season(): void
    {
        $invoice = $this->invoice();
        $this->assertSame($this->season->id, $invoice->season_id);
        $this->manager()->callTableAction('editFinance', $invoice, data: ['notes' => 'Changed'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertSame('Changed', $invoice->fresh()->notes);
        $this->manager()->mountTableAction('positions', $invoice)->assertSet('mountedActions.0.name', 'positions');
        $lines = Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class, 'seasonContextId' => $this->season->id]);
        $lines->callTableAction('addLine', data: ['description' => 'Fixture Line', 'gross_amount' => '25.00', 'discount' => '5.00'])
            ->assertHasNoTableActionErrors()->assertNoRedirect()->assertDispatched('season-finance-updated');
        $line = $invoice->lines()->sole();
        $this->assertEquals(20, $line->net_amount);
        $lines->callTableAction('editLine', $line, data: ['gross_amount' => '30.00'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertEquals(25, $line->fresh()->net_amount);
        $lines->callTableAction('copyLine', $line->fresh())->assertHasNoTableActionErrors()->assertNoRedirect();
        $copy = $invoice->lines()->whereKeyNot($line->id)->sole();
        $this->assertEquals(25, $copy->net_amount);
        $this->assertNull($copy->contribution);
        $lines->callTableAction('removeLine', $line->fresh(), data: ['reason' => 'Synthetic removal'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertFalse($line->fresh()->active);
        $lines->filterTable('active', false)->callTableAction('restoreLine', $line->fresh(), data: ['reason' => 'Synthetic restore'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertTrue($line->fresh()->active);
        $this->manager()->callTableAction('releaseFinance', $invoice->fresh())->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertSame('released', $invoice->fresh()->workflowStatus->code);
        $this->manager()->assertTableActionHidden('editFinance', $invoice->fresh());
        $this->manager()->callTableAction('cancelFinance', $invoice->fresh(), data: ['reason' => 'Synthetic cancellation'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertSame('cancelled', $invoice->fresh()->workflowStatus->code);
        $this->assertGreaterThan(0, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_expense_header_positions_and_transition_remain_inside_season(): void
    {
        $this->manager(false)->callTableAction('createFinance', data: $this->expenseData)->assertHasNoTableActionErrors();
        $expense = Expense::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame($this->season->id, $expense->season_id);
        $this->manager(false)->callTableAction('editFinance', $expense, data: ['notes' => 'Changed'])->assertHasNoTableActionErrors()->assertNoRedirect();
        $this->assertSame('Changed', $expense->fresh()->notes);
        $type = $this->insert('expense_types', ['name' => 'Fixture', 'receipt_required' => false]);
        $lines = Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $expense->fresh(), 'pageClass' => ViewExpense::class, 'seasonContextId' => $this->season->id]);
        $lines->callTableAction('newLine', data: ['expense_type_id' => $type, 'amount' => '20.00', 'active' => true])->assertHasNoTableActionErrors()->assertNoRedirect()->assertDispatched('season-finance-updated');
        $line = $expense->lines()->sole();
        $lines->callTableAction('editLine', $line, data: ['amount' => '25.00'])->assertHasNoTableActionErrors();
        $this->assertEquals(25, $line->fresh()->amount);
        $line->forceFill(['storage_reference' => 'fixture/private.pdf', 'suggested_amount' => 20, 'suggestion_accepted' => false])->save();
        $lines->callTableAction('copyLine', $line->fresh())->assertHasNoTableActionErrors()->assertNoRedirect();
        $copy = $expense->lines()->whereKeyNot($line->id)->sole();
        $this->assertNull($copy->storage_reference);
        $this->assertNull($copy->suggested_amount);
        $this->assertEquals(25, $copy->amount);
        $lines->callTableAction('deactivateRecord', $copy)->assertHasNoTableActionErrors();
        $this->assertFalse($copy->fresh()->active);
        $lines->filterTable('active', false)->callTableAction('restoreRecord', $copy->fresh())->assertHasNoTableActionErrors();
        $this->assertTrue($copy->fresh()->active);
        $target = $this->db->table('clubora.expense_statuses')->where('tenant_id', $this->tenant->id)->where('code', 'F')->value('id');
        $this->manager(false)->callTableAction('transition', $expense->fresh(), data: ['status_id' => $target, 'reason' => 'Synthetic test approval'])->assertHasNoTableActionErrors();
        $this->assertSame('F', $expense->fresh()->status->code);
    }

    public function test_foreign_season_record_and_line_context_are_rejected(): void
    {
        $invoice = $this->invoice();
        $other = $this->insert('seasons', ['name' => 'Other Season']);
        Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class, 'seasonContextId' => $other])->assertForbidden();
        $invoice->forceFill(['season_id' => $other])->save();
        $this->manager()->call('saveSeasonFinance', $invoice->id, ['revision' => $invoice->revision, 'notes' => 'Forbidden'])->assertStatus(404);
        $this->assertSame('Fixture', $invoice->fresh()->notes);
    }

    public function test_expense_cannot_move_to_another_season(): void
    {
        $other = $this->insert('seasons', ['name' => 'Other Season']);
        $team = $this->insert('teams', ['name' => 'Other Team']);
        $otherTeam = $this->insert('team_seasons', ['season_id' => $other, 'team_id' => $team, 'label' => 'Other']);
        $this->manager(false)->call('saveSeasonFinance', null, array_replace($this->expenseData, ['team_season_id' => $otherTeam]))->assertStatus(404);
        $this->assertSame(0, Expense::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_foreign_tenant_invoice_and_changed_season_context_are_rejected(): void
    {
        $invoice = $this->invoice();
        $lines = Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class, 'seasonContextId' => $this->season->id]);
        $invoice->forceFill(['season_id' => $this->insert('seasons', ['name' => 'Moved'])])->save();
        $lines->call('$refresh')->assertForbidden();
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(10), 'name' => 'Other']);
        $foreignMember = new Member;
        $foreignMember->forceFill(['tenant_id' => $foreign->id, 'first_name' => 'Other'])->save();
        $foreignInvoice = new Invoice;
        $foreignInvoice->forceFill(['tenant_id' => $foreign->id, 'member_id' => $foreignMember->id])->save();
        $this->manager()->call('saveSeasonFinance', $foreignInvoice->id, ['revision' => 1])->assertStatus(404);
    }

    public function test_table_refresh_preserves_search_and_filters(): void
    {
        $component = $this->manager()->searchTable('Fixture')->filterTable('active', false);
        $filters = $component->get('tableFilters');
        $component->dispatch('season-finance-updated')->assertSet('tableSearch', 'Fixture')->assertSet('tableFilters', $filters);
    }

    public function test_stale_revision_imported_records_and_revoked_role_are_protected(): void
    {
        $invoice = $this->invoice();
        $component = $this->manager()->mountTableAction('editFinance', $invoice);
        $invoice->forceFill(['revision' => $invoice->revision + 1])->save();
        $component->callMountedTableAction()->assertHasTableActionErrors();
        $invoice->forceFill(['legacy_id' => 987654])->save();
        $this->manager()->assertTableActionHidden('editFinance', $invoice);
        $this->manager()->call('saveSeasonFinance', $invoice->id, ['revision' => $invoice->revision, 'notes' => 'Forbidden'])->assertForbidden();
        $component = $this->manager();
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $component->call('saveSeasonFinance', $invoice->id, ['revision' => $invoice->revision, 'notes' => 'Forbidden'])->assertForbidden();
    }
}
