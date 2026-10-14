<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Bank;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\FieldAvailability;
use App\ClubUnio\Models\FootballClub;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\MemberKey;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\SavedView;
use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\SignageNotice;
use App\ClubUnio\Models\SpotsRecipient;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TenantAccount;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use App\ClubUnio\Pages\ContributionPreview;
use App\ClubUnio\Pages\Login;
use App\ClubUnio\Pages\SfvCompetitions;
use App\ClubUnio\Pages\SignagePreview;
use App\ClubUnio\Resources\AccountMemberLinkResource\Pages\ListAccountMemberLinks;
use App\ClubUnio\Resources\BankAccountReportResource;
use App\ClubUnio\Resources\BankAccountReportResource\Pages\ListBankAccounts;
use App\ClubUnio\Resources\CompetitionResource\Pages\ManageCompetitions;
use App\ClubUnio\Resources\ContributionPriceResource\Pages\ManagePrices;
use App\ClubUnio\Resources\CountryResource\Pages\ManageCountries;
use App\ClubUnio\Resources\ExpenseResource\Pages\ViewExpense;
use App\ClubUnio\Resources\ExpenseTypeResource\Pages\ManageRecords;
use App\ClubUnio\Resources\FacilityStatusResource\Pages\ManageStatuses;
use App\ClubUnio\Resources\FamilyMemberResource\Pages\ManageFamilyMembers;
use App\ClubUnio\Resources\FamilyReportResource;
use App\ClubUnio\Resources\FamilyReportResource\Pages\ListFamilies;
use App\ClubUnio\Resources\FieldAvailabilityResource\Pages\ManageFields;
use App\ClubUnio\Resources\FootballClubResource;
use App\ClubUnio\Resources\FootballClubResource\Pages\ManageClubs;
use App\ClubUnio\Resources\InvoiceResource\Pages\ListInvoices;
use App\ClubUnio\Resources\InvoiceResource\Pages\ViewInvoice;
use App\ClubUnio\Resources\KeyReportResource;
use App\ClubUnio\Resources\KeyReportResource\Pages\ListKeys;
use App\ClubUnio\Resources\MemberReportResource\Pages\ListMemberReports;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Resources\MemberResource\Pages\ListMembers;
use App\ClubUnio\Resources\MemberResource\Pages\ViewMember;
use App\ClubUnio\Resources\MemberStatusResource\Pages\ManageMemberStatuses;
use App\ClubUnio\Resources\MemberTypeResource\Pages\ManageMemberTypes;
use App\ClubUnio\Resources\MessageTemplateResource\Pages\ListMessageTemplates;
use App\ClubUnio\Resources\ObjectChangeResource;
use App\ClubUnio\Resources\OpenInvoiceResource\Pages\ListOpenInvoices;
use App\ClubUnio\Resources\PassiveMemberResource\Pages\ListPassiveMembers;
use App\ClubUnio\Resources\PaymentReportResource;
use App\ClubUnio\Resources\PlayerReportResource;
use App\ClubUnio\Resources\PlayerReportResource\Pages\ListPlayers;
use App\ClubUnio\Resources\RegistrationResource;
use App\ClubUnio\Resources\RegistrationResource\Pages\ListRegistrations;
use App\ClubUnio\Resources\Relations\BankAccountsRelationManager;
use App\ClubUnio\Resources\Relations\FunctionsRelationManager;
use App\ClubUnio\Resources\Relations\InvoiceLinesRelationManager;
use App\ClubUnio\Resources\Relations\NotesRelationManager;
use App\ClubUnio\Resources\SignageNoticeResource\Pages\ManageNotices;
use App\ClubUnio\Resources\SpotsRecipientResource\Pages\ListRecipients;
use App\ClubUnio\Resources\StaffReportResource;
use App\ClubUnio\Resources\StatusStyleResource\Pages\ManageStyles;
use App\ClubUnio\Resources\TeamPlayerResource\Pages\ManageTeamPlayers;
use App\ClubUnio\Resources\TeamResource\Pages\ManageTeams;
use App\ClubUnio\Resources\TeamSeasonResource\Pages\ListTeamSeasons;
use App\ClubUnio\Resources\TenantAccountResource\Pages\ManageAccounts;
use App\ClubUnio\Resources\TrainingOptionResource\Pages\ManageOptions;
use App\ClubUnio\Resources\TrainingSlotResource\Pages\ManageSlots;
use App\ClubUnio\Resources\UnassignedMemberResource\Pages\ListUnassignedMembers;
use App\ClubUnio\Services\AccountAdministrationService;
use App\ClubUnio\Services\AccountLinkService;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\BankAccountService;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\CatalogService;
use App\ClubUnio\Services\ContributionBatchService;
use App\ClubUnio\Services\ContributionInvoiceService;
use App\ClubUnio\Services\ContributionTrainingImporter;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\ExpenseNotificationService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\FacilityImporter;
use App\ClubUnio\Services\FacilityService;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\FootballClubImporter;
use App\ClubUnio\Services\InvoiceAccessService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberNoteService;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\MembershipReportService;
use App\ClubUnio\Services\MessageEventCatalog;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationMessageService;
use App\ClubUnio\Services\RegistrationReviewService;
use App\ClubUnio\Services\ReportExportService;
use App\ClubUnio\Services\SavedViewService;
use App\ClubUnio\Services\SettingsService;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SignageService;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\StatusStyleService;
use App\ClubUnio\Services\TeamAssignmentService;
use App\ClubUnio\Services\TestMailService;
use App\ClubUnio\Widgets\ClubOverview;
use App\ClubUnio\Widgets\ExpensesBySeason;
use App\ClubUnio\Widgets\ExpensesByStatus;
use App\ClubUnio\Widgets\InvoicesBySeason;
use App\ClubUnio\Widgets\InvoicesByStatus;
use App\ClubUnio\Widgets\MembersByAge;
use App\ClubUnio\Widgets\MembersByStatus;
use App\ClubUnio\Widgets\NewMembersMonthly;
use App\ClubUnio\Widgets\OpenInvoicesTable;
use App\ClubUnio\Widgets\PendingExpensesTable;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClubUnioTest extends TestCase
{
    public function test_key_report_preserves_archived_holders_filters_exports_and_scope(): void
    {
        $this->assertTrue(KeyReportResource::canViewAny());
        $member = Member::where('tenant_id', $this->own->id)->sole();
        $foreign = Member::where('tenant_id', $this->other->id)->sole();
        $member->forceFill(['archived_at' => now()])->save();
        $id = $this->db->table('member_keys')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id,
            'number' => '=KEY', 'name' => 'Test key', 'color' => 'Blue']);
        $this->db->table('member_keys')->insert(['tenant_id' => $this->other->id, 'member_id' => $foreign->id,
            'number' => 'FOREIGN-KEY', 'name' => 'Foreign key', 'color' => 'Red']);
        $this->get('/key-reports')->assertOk();
        Livewire::test(ListKeys::class)
            ->searchTable('Test key')->filterTable('color', 'Blue')->assertSee('=KEY')->assertDontSee('FOREIGN-KEY')
            ->callTableAction('export')->assertHasNoActionErrors()->assertFileDownloaded('schluesselliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $response = app(ReportExportService::class)->download($this->actor, $this->own, 'keys', MemberKey::query());
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=KEY", $csv);
        $this->assertStringNotContainsString('FOREIGN-KEY', $csv);
        $this->assertFalse(KeyReportResource::canCreate());
        $this->assertFalse(KeyReportResource::canEdit(MemberKey::findOrFail($id)));
        $this->get('/clubunio/'.$this->other->slug.'/key-reports')->assertNotFound();
        Account::whereKey($this->actor->id)->update(['active' => false]);
        $this->assertFalse(KeyReportResource::canViewAny());
    }

    public function test_spots_report_groups_active_families_and_unlinked_eligible_members(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->sole();
        $status = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'spots-test', 'name' => 'Member']);
        $type = $this->db->table('member_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'spots-test', 'name' => 'Active']);
        $member->forceFill(['spots' => true, 'status_id' => $status, 'type_id' => $type])->save();
        $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'SPOTS-FAMILY']);
        $this->db->table('families')->insert(['tenant_id' => $this->other->id, 'name' => 'FOREIGN-FAMILY']);
        $query = fn () => SpotsRecipient::where('tenant_id', $this->own->id);
        $this->assertSame(2, $query()->count());
        $link = $this->db->table('family_members')->insertGetId(['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $member->id]);
        $this->assertSame(['f-'.$family], $query()->pluck('id')->all());
        $this->get('/spots-recipients')->assertOk();
        Livewire::test(ListRecipients::class)
            ->filterTable('kind', 'family')->searchTable('SPOTS-FAMILY')->assertSee('SPOTS-FAMILY')->assertDontSee('FOREIGN-FAMILY')
            ->callTableAction('export')->assertHasNoActionErrors()->assertFileDownloaded('spots-versandliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $this->db->table('family_members')->where('id', $link)->update(['active' => false]);
        $this->assertSame(2, $query()->count());
        $this->db->table('families')->where('id', $family)->update(['active' => false]);
        $this->assertSame(['m-'.$member->id], $query()->pluck('id')->all());
        app(CatalogService::class)->save($this->actor, $this->own, MemberStatus::class, $status,
            ['name' => 'Member', 'sort_order' => 0, 'active' => true, 'spots_eligible' => false]);
        $this->assertSame(0, $query()->count());
        $this->db->table('member_statuses')->where('id', $status)->update(['spots_eligible' => true]);
        $member->forceFill(['exit_date' => now('Europe/Zurich')->toDateString()])->save();
        $this->assertSame(0, $query()->count());
        $member->forceFill(['exit_date' => null])->save();
        $this->db->table('member_statuses')->where('id', $status)->update(['ends_membership' => true]);
        $this->assertSame(0, $query()->count());
        $this->get('/clubunio/'.$this->other->slug.'/spots-recipients')->assertNotFound();
    }

    public function test_new_reports_reject_trainer_access_and_export(): void
    {
        $this->db->table('tenant_accounts')->where('tenant_id', $this->own->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->get('/key-reports')->assertForbidden();
        $this->get('/spots-recipients')->assertForbidden();
        foreach (['keys' => MemberKey::class, 'spots' => SpotsRecipient::class] as $kind => $model) {
            try {
                app(ReportExportService::class)->download($this->actor, $this->own, $kind, $model::query());
                $this->fail('Trainer export must be denied');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    private function contributionFixture(): array
    {
        foreach (['draft' => 'Entwurf', 'released' => 'Freigegeben', 'cancelled' => 'Storniert'] as $code => $name) {
            $this->db->table('invoice_workflow_statuses')->insert(['tenant_id' => $this->own->id, 'code' => $code, 'name' => $name]);
        }
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $gender = $this->db->table('genders')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'contribution', 'name' => 'Fixture']);
        $member->forceFill(['gender_id' => $gender, 'birth_date' => '2010-01-01'])->save();
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Contribution season']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Contribution team']);
        $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Contribution assignment']);
        $this->db->table('team_players')->insert(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $ts]);
        $price = $this->db->table('contribution_prices')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Contribution tariff', 'amount' => '250.0050']);
        $rule = $this->db->table('contribution_rules')->insertGetId(['tenant_id' => $this->own->id, 'season_id' => $season, 'price_id' => $price, 'gender_id' => $gender, 'year_from' => 2010, 'year_to' => 2010]);
        $this->db->table('invoice_line_statuses')->insert(['tenant_id' => $this->own->id, 'code' => 'P', 'name' => 'Pendent']);

        return [$member, $season, $price, $rule];
    }

    public function test_invoice_workflow_edit_release_cancel_and_stale_revision(): void
    {
        [$member, $season] = $this->contributionFixture();
        $contributions = app(ContributionInvoiceService::class);
        $preview = $contributions->preview($this->actor, $this->own, $member->id, $season);
        $invoice = $contributions->create($this->actor, $this->own, $member->id, $season, $preview['fingerprint']);
        $service = app(InvoiceWorkflowService::class);
        $this->assertTrue($service->editable($this->actor, $this->own, $invoice));
        $line = $invoice->activeLines()->sole();
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 1, 'line', [
            'line_id' => $line->id, 'description' => 'Adjusted contribution', 'gross_amount' => '120.005', 'discount' => '20.00']);
        $this->assertSame('100.0100', $line->fresh()->net_amount);
        try {
            $service->change($this->actor, $this->own, $invoice->id, 1, 'header', ['notes' => 'stale']);
            $this->fail('Stale revision accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('revision', $error->errors());
        }
        $this->get('/invoices/'.$invoice->id)->assertOk()->assertSee('PDF-Vorschau');
        Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class])
            ->assertSee('Adjusted contribution');
        $response = $this->get('/clubora-invoice-pdf/'.$this->own->slug.'/'.$invoice->id.'/local');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        if ($path = getenv('CLUBUNIO_PDF_TEST_OUTPUT')) {
            file_put_contents($path, $response->getContent());
        }
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 2, 'release');
        $this->assertSame('released', $invoice->workflowStatus->code);
        $this->assertFalse($service->editable($this->actor, $this->own, $invoice));
        $this->assertNull($invoice->bexio_invoice_id);
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 3, 'cancel', ['reason' => 'Fixture correction']);
        $this->assertSame('cancelled', $invoice->workflowStatus->code);
        $this->assertSame(1, $invoice->activeLines()->count());
        $this->assertSame(4, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'invoices')->count());
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->own, $invoice->id, 4, 'header', ['notes' => 'blocked']);
    }

    public function test_invoice_restore_line_and_archive_preserve_links_and_history(): void
    {
        [$member, $season] = $this->contributionFixture();
        $contributions = app(ContributionInvoiceService::class);
        $invoice = $contributions->create($this->actor, $this->own, $member->id, $season, $contributions->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        $service = app(InvoiceWorkflowService::class);
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 1, 'line', ['description' => 'Manual', 'gross_amount' => '20', 'discount' => '5']);
        $line = $invoice->activeLines()->where('description', 'Manual')->sole();
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 2, 'remove_line', ['line_id' => $line->id, 'reason' => 'Correction']);
        Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
            ->filterTable('active', false)
            ->callTableAction('restoreLine', $line, data: ['revision' => 3, 'reason' => 'Restore correct line'])->assertHasNoTableActionErrors();
        $this->assertTrue($line->fresh()->active);
        $this->assertSame('15.0000', $line->fresh()->net_amount);
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 4, 'cancel', ['reason' => 'Not required']);
        $links = $this->db->table('team_players')->where('tenant_id', $this->own->id)->pluck('invoice_line_id')->all();
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('archive', data: ['revision' => 5, 'reason' => 'Archive cancelled invoice'])->assertHasNoActionErrors();
        $this->assertFalse($invoice->fresh()->active);
        $this->get('/invoices/'.$invoice->id)->assertOk();
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('restore', data: ['revision' => 6, 'reason' => 'Review archive'])->assertHasNoActionErrors();
        $this->assertTrue($invoice->fresh()->active);
        $this->assertSame('cancelled', $invoice->fresh()->workflowStatus->code);
        $this->assertSame(2, $invoice->activeLines()->count());
        $this->assertSame($links, $this->db->table('team_players')->where('tenant_id', $this->own->id)->pluck('invoice_line_id')->all());
        $this->assertSame(7, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'invoices')->count());
    }

    public function test_invoice_archive_rejects_draft_and_revoked_account(): void
    {
        [$member, $season] = $this->contributionFixture();
        $c = app(ContributionInvoiceService::class);
        $invoice = $c->create($this->actor, $this->own, $member->id, $season, $c->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        $service = app(InvoiceWorkflowService::class);
        try {
            $service->change($this->actor, $this->own, $invoice->id, 1, 'archive', ['reason' => 'Not allowed']);
            $this->fail('Draft archived');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(1, $invoice->fresh()->revision);
        Account::whereKey($this->actor->id)->update(['active' => false]);
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->own, $invoice->id, 1, 'header', ['notes' => 'Revoked']);
    }

    public function test_invoice_release_rejects_inconsistent_position_amount(): void
    {
        [$member, $season] = $this->contributionFixture();
        $c = app(ContributionInvoiceService::class);
        $invoice = $c->create($this->actor, $this->own, $member->id, $season, $c->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        $invoice->activeLines()->update(['net_amount' => '999.00']);
        try {
            app(InvoiceWorkflowService::class)->change($this->actor, $this->own, $invoice->id, 1, 'release');
            $this->fail('Inconsistent invoice released');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame(1, $invoice->fresh()->revision);
        $this->assertSame('draft', $invoice->fresh()->workflowStatus->code);
    }

    public function test_signage_live_day_rollover_preserves_manual_preview(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T23:59:00+02:00'));
        $page = Livewire::test(SignagePreview::class)->assertSet('date', '2026-10-12')->assertSet('followToday', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T00:01:00+02:00'));
        $page->call('refreshDisplay')->assertSet('date', '2026-10-13');
        $page->set('date', '2026-10-20')->assertSet('followToday', false)->call('refreshDisplay')->assertSet('date', '2026-10-20');
        $page->set('followToday', true)->assertSet('date', '2026-10-13');
        $this->travelBack();
    }

    public function test_invoice_draft_line_removal_retains_audit_and_protects_contributions(): void
    {
        [$member, $season] = $this->contributionFixture();
        $contributions = app(ContributionInvoiceService::class);
        $preview = $contributions->preview($this->actor, $this->own, $member->id, $season);
        $invoice = $contributions->create($this->actor, $this->own, $member->id, $season, $preview['fingerprint']);
        $service = app(InvoiceWorkflowService::class);
        $contribution = $invoice->activeLines()->sole();
        try {
            $service->change($this->actor, $this->own, $invoice->id, 1, 'remove_line', ['line_id' => $contribution->id, 'reason' => 'Test']);
            $this->fail('Contribution removed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('line_id', $e->errors());
        }
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 1, 'line', ['description' => 'Extra', 'gross_amount' => '10', 'discount' => '0']);
        $line = $invoice->activeLines()->where('description', 'Extra')->sole();
        Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
            ->callTableAction('removeLine', $line, data: ['revision' => 2, 'reason' => 'Not required'])->assertHasNoTableActionErrors();
        $invoice = $invoice->fresh();
        $this->assertFalse($line->fresh()->active);
        $this->assertSame(1, $invoice->activeLines()->count());
        $this->assertSame(3, $invoice->revision);
        $audit = $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'invoices')->orderByDesc('id')->value('after_value');
        $this->assertSame('remove_line', json_decode($audit, true)['action']);
        try {
            $service->change($this->actor, $this->own, $invoice->id, 2, 'remove_line', ['line_id' => $line->id, 'reason' => 'Stale']);
            $this->fail('Stale accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $invoice = $service->change($this->actor, $this->own, $invoice->id, 3, 'release');
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->own, $invoice->id, 4, 'remove_line', ['line_id' => $contribution->id, 'reason' => 'Blocked']);
    }

    public function test_invoice_bexio_pdf_ignores_activation_and_checks_access(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $invoice = new Invoice;
        $invoice->forceFill(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'bexio_invoice_id' => '12345'])->save();
        foreach (['BEXIO_CLIENT_ID' => 'fixture-client', 'BEXIO_CLIENT_SECRET' => 'fixture-secret', 'BEXIO_AKTIV' => 'Nein'] as $key => $value) {
            $this->db->table('tenant_settings')->insert(['tenant_id' => $this->own->id, 'area' => 'bexio', 'key' => $key,
                'label' => $key, 'value_type' => 'string', 'is_secret' => true, 'enabled' => false, 'encrypted_value' => Crypt::encryptString($value)]);
        }
        $bexio = app(BexioConnectionService::class);
        $bexio->connect($this->own, ['access_token' => 'fixture-token', 'refresh_token' => 'fixture-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer'], $bexio->configuration($this->own)['fingerprint'], $this->actor->id);
        Http::preventStrayRequests();
        Http::fake(['api.bexio.com/*' => Http::response(['content' => base64_encode('%PDF-1.7 fixture')])]);
        $url = '/clubora-invoice-pdf/'.$this->own->slug.'/'.$invoice->id.'/bexio';
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Accept', 'application/json') && $request->hasHeader('Authorization', 'Bearer fixture-token'));
        $this->get('/clubora-invoice-pdf/'.$this->other->slug.'/'.$invoice->id.'/bexio')->assertForbidden();
        [$trainer] = $this->portalActor('trainer');
        $this->actingAs($trainer, 'clubunio')->get($url)->assertForbidden();
        Http::assertSentCount(1);
        $this->actingAs($this->actor, 'clubunio');
        Http::swap(new Factory);
        Http::fake(['api.bexio.com/*' => Http::response(['content' => base64_encode('<html>not pdf</html>')])]);
        $this->get($url)->assertStatus(503)->assertDontSee('fixture-token');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.bexio.com/*' => Http::response('secret provider body', 401), 'auth.bexio.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get($url)->assertStatus(503)->assertDontSee('secret provider body');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'api.bexio.com/*' => Http::sequence()->push('', 401)->push(['content' => base64_encode('%PDF-1.7 refreshed')]),
            'auth.bexio.com/*' => Http::response(['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        ]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer refreshed-access'));
    }

    public function test_invoice_batch_preview_creation_and_replay(): void
    {
        [$member, $season] = $this->contributionFixture();
        $service = app(ContributionBatchService::class);
        $batch = $service->preview($this->actor, $this->own, $season);
        $this->assertCount(1, $batch['rows']);
        $this->assertNull($batch['next']);
        $result = $service->create($this->actor, $this->own, $batch);
        $this->assertArrayHasKey('invoice_id', $result[0]);
        $service->create($this->actor, $this->own, $batch);
        $this->assertSame(1, Invoice::where('tenant_id', $this->own->id)->count());
        $this->assertSame($result[0]['invoice_id'], $service->preview($this->actor, $this->own, $season)['rows'][0]['existing_invoice_id']);
        Livewire::test(ContributionPreview::class)->callAction('seasonRun', data: ['season_id' => $season])
            ->assertHasNoActionErrors()->assertSee('Bereits vorhanden');
    }

    public function test_invoice_workflow_rejects_foreign_lines_discount_and_import_edits(): void
    {
        [$member, $season] = $this->contributionFixture();
        $c = app(ContributionInvoiceService::class);
        $invoice = $c->create($this->actor, $this->own, $member->id, $season, $c->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        $service = app(InvoiceWorkflowService::class);
        try {
            $service->change($this->actor, $this->own, $invoice->id, 1, 'line', ['description' => 'Bad', 'gross_amount' => '10', 'discount' => '11']);
            $this->fail('Excessive discount accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('discount', $error->errors());
        }
        $this->assertSame(1, (int) $invoice->fresh()->revision);
        $invoice->forceFill(['legacy_id' => 123456])->save();
        $this->assertFalse($service->editable($this->actor, $this->own, $invoice));
        $this->expectException(HttpException::class);
        $service->change($this->actor, $this->own, $invoice->id, 1, 'release');
    }

    public function test_invoice_edit_forms_and_foreign_line_protection(): void
    {
        [$member, $season] = $this->contributionFixture();
        $c = app(ContributionInvoiceService::class);
        $invoice = $c->create($this->actor, $this->own, $member->id, $season, $c->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('editHeader', data: ['revision' => 1, 'notes' => 'Checked header'])->assertHasNoActionErrors();
        $this->assertSame('Checked header', $invoice->fresh()->notes);
        Livewire::test(InvoiceLinesRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => ViewInvoice::class])
            ->callTableAction('addLine', data: ['revision' => 2, 'description' => 'Additional line', 'gross_amount' => '25', 'discount' => '0'])->assertHasNoTableActionErrors();
        $this->assertSame(2, $invoice->activeLines()->count());
        $foreign = new Invoice;
        $foreign->forceFill(['tenant_id' => $this->own->id, 'member_id' => $member->id])->save();
        $line = new InvoiceLine;
        $line->forceFill(['tenant_id' => $this->own->id, 'invoice_id' => $foreign->id, 'description' => 'Other invoice'])->save();
        try {
            app(InvoiceWorkflowService::class)->change($this->actor, $this->own, $invoice->id, 3, 'line', ['line_id' => $line->id, 'description' => 'Attack', 'gross_amount' => '20', 'discount' => '0']);
            $this->fail('Foreign line accepted');
        } catch (ModelNotFoundException) {
            $this->assertSame('Other invoice', $line->fresh()->description);
        }
        [$trainer] = $this->portalActor('trainer');
        $this->assertFalse(app(InvoiceWorkflowService::class)->editable($trainer, $this->own, $invoice->fresh()));
        $this->expectException(HttpException::class);
        app(InvoiceWorkflowService::class)->change($trainer, $this->own, $invoice->id, 3, 'release');
    }

    public function test_invoice_batch_keyset_bound_and_draft_portal_visibility(): void
    {
        [$member, $season] = $this->contributionFixture();
        $ts = $this->db->table('team_seasons')->where('tenant_id', $this->own->id)->value('id');
        for ($i = 0; $i < 25; $i++) {
            $id = $this->db->table('members')->insertGetId(['tenant_id' => $this->own->id, 'first_name' => 'Batch', 'last_name' => (string) $i]);
            $this->db->table('team_players')->insert(['tenant_id' => $this->own->id, 'member_id' => $id, 'team_season_id' => $ts]);
        }
        $service = app(ContributionBatchService::class);
        $page = $service->preview($this->actor, $this->own, $season);
        $this->assertCount(25, $page['rows']);
        $this->assertNotNull($page['next']);
        $next = $service->preview($this->actor, $this->own, $season, $page['next']);
        $this->assertCount(1, $next['rows']);
        $this->assertNull($next['next']);
        $this->assertNotContains($next['rows'][0]['member_id'], array_column($page['rows'], 'member_id'));
        $this->assertArrayHasKey('error', $next['rows'][0]);
        $c = app(ContributionInvoiceService::class);
        $invoice = $c->create($this->actor, $this->own, $member->id, $season, $c->preview($this->actor, $this->own, $member->id, $season)['fingerprint']);
        [$player] = $this->portalActor('member');
        $this->assertFalse(app(InvoiceAccessService::class)->visible($player, $this->own)->whereKey($invoice->id)->exists());
    }

    public function test_contribution_preview_rounding_discount_draft_and_idempotency(): void
    {
        [$member, $season] = $this->contributionFixture();
        $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Contribution family']);
        $this->db->table('family_members')->insert(['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $member->id, 'discount' => '30.00']);
        $service = app(ContributionInvoiceService::class);
        $preview = $service->preview($this->actor, $this->own, $member->id, $season);
        $this->assertSame(25001, $preview['gross_cents']);
        $this->assertSame(3000, $preview['discount_cents']);
        $this->assertSame(22001, $preview['net_cents']);
        $invoice = $service->create($this->actor, $this->own, $member->id, $season, $preview['fingerprint']);
        $this->assertSame('220.0100', $invoice->lines()->sole()->net_amount);
        $this->assertSame('P', $invoice->lines()->sole()->status->code);
        $this->assertSame($invoice->lines()->sole()->id, $member->teamPlayers()->sole()->invoice_line_id);
        $this->assertNull($invoice->bexio_invoice_id);
        $this->assertSame($invoice->id, $service->create($this->actor, $this->own, $member->id, $season, $preview['fingerprint'])->id);
        $this->assertSame(1, Invoice::where('tenant_id', $this->own->id)->count());
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'invoices')->count());
    }

    public function test_contribution_exemption_and_stale_preview(): void
    {
        [$member, $season, $price] = $this->contributionFixture();
        $service = app(ContributionInvoiceService::class);
        $member->forceFill(['honorary' => true])->save();
        $preview = $service->preview($this->actor, $this->own, $member->id, $season);
        $this->assertSame(0, $preview['net_cents']);
        $member->forceFill(['honorary' => false, 'free_membership' => true])->save();
        $this->assertSame(0, $service->preview($this->actor, $this->own, $member->id, $season)['net_cents']);
        $this->db->table('contribution_prices')->where('id', $price)->update(['amount' => 300]);
        try {
            $service->create($this->actor, $this->own, $member->id, $season, $preview['fingerprint']);
            $this->fail('Stale contribution accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('contribution', $e->errors());
        }
        $this->assertSame(0, Invoice::where('tenant_id', $this->own->id)->count());
    }

    public function test_contribution_requires_one_rule_and_one_family(): void
    {
        [$member, $season, $price, $rule] = $this->contributionFixture();
        $service = app(ContributionInvoiceService::class);
        foreach (['missing', 'duplicate', 'families'] as $case) {
            if ($case === 'missing') {
                $this->db->table('contribution_rules')->where('id', $rule)->update(['active' => false]);
            } elseif ($case === 'duplicate') {
                $this->db->table('contribution_rules')->where('id', $rule)->update(['active' => true]);
                $duplicate = $this->db->table('contribution_rules')->insertGetId(['tenant_id' => $this->own->id, 'season_id' => $season, 'price_id' => $price, 'gender_id' => $member->gender_id, 'year_from' => 2010, 'year_to' => 2010]);
            } else {
                $this->db->table('contribution_rules')->where('id', $duplicate)->update(['active' => false]);
                foreach (['A', 'B'] as $name) {
                    $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => $name]);
                    $this->db->table('family_members')->insert(['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $member->id, 'discount' => 10]);
                }
            }
            try {
                $service->preview($this->actor, $this->own, $member->id, $season);
                $this->fail('Ambiguous contribution accepted: '.$case);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('contribution', $e->errors());
            }
        }
    }

    public function test_contribution_page_preview_and_confirm_and_role_protection(): void
    {
        [$member, $season] = $this->contributionFixture();
        Livewire::test(ContributionPreview::class)
            ->callAction('calculate', data: ['member_id' => $member->id, 'season_id' => $season])->assertHasNoActionErrors()
            ->assertSee('250.01')->call('createDraft')->assertHasNoErrors()->assertRedirect();
        $this->get('/clubunio/'.$this->other->slug.'/contribution-preview')->assertNotFound();
        [$trainer] = $this->portalActor('trainer');
        $this->expectException(HttpException::class);
        app(ContributionInvoiceService::class)->preview($trainer, $this->own, $member->id, $season);
    }

    public function test_family_report_preserves_links_and_combines_saved_filters(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $status = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'report', 'name' => 'Report status']);
        $member->forceFill(['status_id' => $status, 'birth_date' => '1980-01-01'])->save();
        $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Report family', 'city' => 'Report city']);
        $id = $this->db->table('family_members')->insertGetId(['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $member->id, 'discount' => 25]);
        $page = Livewire::test(ListFamilies::class)
            ->assertCountTableRecords(1)->assertSee('Report city')->assertSee('Report status')
            ->filterTable('family_id', $family)->filterTable('member_status', $status)
            ->searchTable('OWN-MEMBER')->assertCanSeeTableRecords([FamilyMember::findOrFail($id)]);
        $page->callAction('saveView', data: ['name' => 'Family report', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ListFamilies::class)
            ->assertSet('tableSearch', 'OWN-MEMBER')->assertCountTableRecords(1)
            ->searchTable('missing')->assertCountTableRecords(0)->assertSet('tableFilters.family_id.value', (string) $family);
        $this->assertFalse(FamilyReportResource::canCreate());
        $this->assertFalse(FamilyReportResource::canEdit(FamilyMember::findOrFail($id)));
        $this->db->table('families')->where('id', $family)->update(['active' => false]);
        $this->assertSame(0, FamilyReportResource::getEloquentQuery()->count());
    }

    public function test_report_exports_filter_scope_mask_and_audit_without_private_notes(): void
    {
        $service = app(ReportExportService::class);
        $iban = 'CH9300762011623852957';
        foreach ([$this->own, $this->other] as $tenant) {
            $member = Member::where('tenant_id', $tenant->id)->firstOrFail();
            $family = $this->db->table('families')->insertGetId(['tenant_id' => $tenant->id, 'name' => '=FORMULA', 'city' => 'Zürich']);
            $this->db->table('family_members')->insert(['tenant_id' => $tenant->id, 'family_id' => $family, 'member_id' => $member->id, 'discount' => 25]);
            $this->db->table('member_bank_accounts')->insert(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'iban_encrypted' => Crypt::encryptString($iban)]);
        }
        foreach (['families' => FamilyMember::class, 'banks' => MemberBankAccount::class] as $kind => $model) {
            $response = $service->download($this->actor, $this->own, $kind, $model::withoutGlobalScopes()->orderBy('id'));
            ob_start();
            $response->sendContent();
            $csv = ob_get_clean();
            $this->assertStringContainsString('OWN-MEMBER', $csv);
            $this->assertStringNotContainsString('FOREIGN-MEMBER', $csv);
            $this->assertStringNotContainsString($iban, $csv);
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            if ($kind === 'families') {
                $this->assertStringContainsString("'=FORMULA", $csv);
                $this->assertStringContainsString('Zürich', $csv);
            } else {
                $this->assertStringContainsString('2957', $csv);
            }
        }
        $audits = $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->pluck('after_value');
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $this->assertSame(1, json_decode($audit, true)['row_count']);
            $this->assertStringNotContainsString('OWN-MEMBER', $audit);
        }
        Livewire::test(ListBankAccounts::class)->searchTable('missing')->callTableAction('export')
            ->assertHasNoActionErrors()->assertFileDownloaded('bankkontenliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $latest = $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->orderByDesc('id')->value('after_value');
        $this->assertSame(0, json_decode($latest, true)['row_count']);
    }

    public function test_player_and_staff_report_exports_preserve_filtered_assignment_rows(): void
    {
        [$member, $season] = $this->contributionFixture();
        $player = TeamPlayer::where('tenant_id', $this->own->id)->firstOrFail();
        $staffId = $this->db->table('team_staff')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $player->team_season_id]);
        $service = app(ReportExportService::class);
        foreach (['players' => TeamPlayer::whereKey($player->id), 'staff' => TeamStaff::whereKey($staffId)] as $kind => $query) {
            $response = $service->download($this->actor, $this->own, $kind, $query);
            ob_start();
            $response->sendContent();
            $csv = ob_get_clean();
            $this->assertStringContainsString('Contribution season', $csv);
            $this->assertStringContainsString('Contribution assignment', $csv);
            $this->assertStringContainsString('OWN-MEMBER', $csv);
        }
        Livewire::test(ListPlayers::class)->filterTable('season', $season)->filterTable('without_contribution', true)
            ->searchTable('OWN-MEMBER')->callTableAction('export')->assertHasNoActionErrors()
            ->assertFileDownloaded('spielerliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $audit = $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->orderByDesc('id')->value('after_value');
        $this->assertSame(1, json_decode($audit, true)['row_count']);
    }

    public function test_parent_contact_export_is_separate_filtered_and_formula_safe(): void
    {
        [$member, $season] = $this->contributionFixture();
        $member->forceFill(['mother_name' => '=HYPERLINK', 'mother_email' => 'mother@example.invalid', 'father_mobile' => '+41790000000'])->save();
        $player = TeamPlayer::where('tenant_id', $this->own->id)->firstOrFail();
        foreach (['players', 'player_contacts'] as $kind) {
            $response = app(ReportExportService::class)->download($this->actor, $this->own, $kind, TeamPlayer::whereKey($player->id));
            ob_start();
            $response->sendContent();
            $csv = ob_get_clean();
            if ($kind === 'player_contacts') {
                $this->assertStringContainsString('mother@example.invalid', $csv);
                $this->assertStringContainsString("'=HYPERLINK", $csv);
                $this->assertStringContainsString("'+41790000000", $csv);
            } else {
                $this->assertStringNotContainsString('mother@example.invalid', $csv);
            }
        }
        Livewire::test(ListPlayers::class)->filterTable('season', $season)->searchTable('Missing person')
            ->callTableAction('exportContacts')->assertHasNoTableActionErrors()
            ->assertFileDownloaded('spieler-kontaktliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
    }

    public function test_bank_details_are_editable_and_exported_without_plain_iban(): void
    {
        $bank = app(CatalogService::class)->save($this->actor, $this->own, Bank::class, null,
            ['name' => 'Detail Bank', 'code' => 'details', 'sort_order' => 0, 'active' => true, 'street' => 'Bankstrasse 1', 'postal_address' => 'Postfach 20',
                'postal_code' => '4450', 'country_code' => 'CH', 'clearing_number' => '1234', 'postal_account' => '12-345-6']);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $id = $this->db->table('member_bank_accounts')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'bank_id' => $bank->id,
            'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $response = app(ReportExportService::class)->download($this->actor, $this->own, 'banks', MemberBankAccount::whereKey($id));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('Bankstrasse 1', $csv);
        $this->assertStringContainsString('Postfach 20', $csv);
        $this->assertStringContainsString('12-345-6', $csv);
        $this->assertStringNotContainsString('CH9300762011623852957', $csv);
    }

    public function test_report_export_rejects_portal_roles_foreign_tenants_and_wrong_models(): void
    {
        $service = app(ReportExportService::class);
        foreach (['member', 'trainer', 'technical_lead'] as $role) {
            [$actor] = $this->portalActor($role);
            try {
                $service->download($actor, $this->own, 'banks', MemberBankAccount::query());
                $this->fail('Portal role exported administrative report');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        try {
            $service->download($this->actor, $this->other, 'banks', MemberBankAccount::query());
            $this->fail('Foreign tenant exported');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        try {
            $service->download($this->actor, $this->own, 'banks', Member::query());
            $this->fail('Wrong model exported');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $this->assertSame(0, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->count());
    }

    public function test_report_export_rejects_oversized_selection_before_audit_or_download(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Export limit fixture']);
        foreach (array_chunk(array_fill(0, 5001, ['tenant_id' => $this->own->id, 'first_name' => 'Export limit fixture']), 500) as $chunk) {
            $this->db->table('members')->insert($chunk);
        }
        $rows = $this->db->table('members')->where('tenant_id', $this->own->id)->where('first_name', 'Export limit fixture')->pluck('id')
            ->map(fn ($id) => ['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $id])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('family_members')->insert($chunk);
        }
        try {
            app(ReportExportService::class)->download($this->actor, $this->own, 'families', FamilyMember::query());
            $this->fail('Oversized export accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('export', $error->errors());
        }
        $this->assertSame(0, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->count());
    }

    public function test_family_report_excludes_archived_members_and_foreign_tenant(): void
    {
        foreach ([$this->own, $this->other] as $tenant) {
            $member = Member::where('tenant_id', $tenant->id)->firstOrFail();
            $family = $this->db->table('families')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Family fixture']);
            $this->db->table('family_members')->insert(['tenant_id' => $tenant->id, 'family_id' => $family, 'member_id' => $member->id]);
        }
        Livewire::test(ListFamilies::class)
            ->assertCountTableRecords(1)->assertDontSee('FOREIGN-MEMBER');
        $this->db->table('members')->where('tenant_id', $this->own->id)->update(['archived_at' => now()]);
        $this->assertSame(0, FamilyReportResource::getEloquentQuery()->count());
    }

    public function test_bank_report_masks_iban_and_filters_staff_without_duplicates(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $iban = 'CH9300762011623852957';
        $account = MemberBankAccount::findOrFail($this->db->table('member_bank_accounts')->insertGetId([
            'tenant_id' => $this->own->id, 'member_id' => $member->id, 'iban_encrypted' => Crypt::encryptString($iban),
        ]));
        $page = Livewire::test(ListBankAccounts::class)
            ->assertCountTableRecords(1)->assertSee('2957')->assertDontSee($iban)->assertSee('Keine Bank zugeordnet');
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Staff season']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Staff team']);
        $page->filterTable('staff_season', $season)->assertCountTableRecords(0);
        foreach (['A', 'B'] as $label) {
            $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'season_id' => $season, 'team_id' => $team, 'label' => $label]);
            $this->db->table('team_staff')->insert(['tenant_id' => $this->own->id, 'team_season_id' => $ts, 'member_id' => $member->id]);
        }
        $page->searchTable('OWN-MEMBER')->assertCanSeeTableRecords([$account])->assertCountTableRecords(1)
            ->callAction('saveView', data: ['name' => 'Staff bank report', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ListBankAccounts::class)
            ->assertCountTableRecords(1)->assertDontSee($iban)->assertSet('tableSearch', 'OWN-MEMBER');
        $this->db->table('team_staff')->where('tenant_id', $this->own->id)->update(['active' => false]);
        $page->searchTable('Fixture')->assertCountTableRecords(0);
        $page->filterTable('staff_season', 'invalid')->assertCountTableRecords(0);
        $this->assertFalse(BankAccountReportResource::canCreate());
        $this->assertFalse(BankAccountReportResource::canEdit($account));
    }

    public function test_new_reports_reject_portal_roles_and_foreign_tenants(): void
    {
        foreach (['family-reports', 'bank-account-reports'] as $slug) {
            $this->get('/'.$slug)->assertOk();
            $this->get('/clubunio/'.$this->other->slug.'/'.$slug)->assertNotFound();
        }
        foreach (['member', 'trainer', 'technical_lead'] as $role) {
            [$actor] = $this->portalActor($role);
            $this->actingAs($actor, 'clubunio');
            foreach (['family-reports', 'bank-account-reports'] as $slug) {
                $this->get('/'.$slug)->assertForbidden();
            }
        }
    }

    public function test_bank_report_scope_inactive_accounts_and_office_access(): void
    {
        foreach ([$this->own, $this->other] as $tenant) {
            $member = Member::where('tenant_id', $tenant->id)->firstOrFail();
            foreach ([true, false] as $active) {
                $this->db->table('member_bank_accounts')->insert(['tenant_id' => $tenant->id, 'member_id' => $member->id,
                    'iban_encrypted' => Crypt::encryptString('CH9300762011623852957'), 'active' => $active]);
            }
        }
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $page = Livewire::test(ListBankAccounts::class)
            ->assertCountTableRecords(1)->assertDontSee('FOREIGN-MEMBER')
            ->filterTable('active', null)->assertCountTableRecords(2)
            ->filterTable('active', false)->assertCountTableRecords(1);
        $foreign = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->other->id, 'name' => 'Other season']);
        $page->filterTable('staff_season', $foreign)->assertCountTableRecords(0);
        $this->db->table('members')->where('tenant_id', $this->own->id)->update(['archived_at' => now()]);
        $page->filterTable('staff_season', null)->filterTable('active', null)->assertCountTableRecords(0);
    }

    public function test_dashboard_months_use_original_recorded_date_not_import_timestamp(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');
        try {
            $this->db->table('clubora.members')->where('tenant_id', $this->own->id)->update(['recorded_at' => null]);
            foreach (['2025-11-01 00:00:00', '2026-10-01 00:00:00', '2025-10-31 23:59:59', '2026-11-01 00:00:00'] as $date) {
                $this->db->table('clubora.members')->insert(['tenant_id' => $this->own->id, 'first_name' => 'Month fixture', 'recorded_at' => $date]);
            }
            $this->db->table('clubora.members')->insert(['tenant_id' => $this->own->id, 'first_name' => 'Archived month', 'recorded_at' => '2026-10-01', 'archived_at' => now()]);
            $data = app(DashboardService::class)->newMembersMonthly($this->actor, $this->own);
            $this->assertCount(12, $data);
            $this->assertSame(1, $data['2025-11']);
            $this->assertSame(1, $data['2026-10']);
            $this->assertSame(2, array_sum($data));
            Livewire::test(NewMembersMonthly::class)->assertSee('Neue Mitglieder pro Monat');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_invoice_status_uses_positions_and_sums_without_duplicates(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $statuses = [];
        foreach (['O', 'B', 'P'] as $code) {
            $statuses[$code] = $this->db->table('clubora.invoice_line_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => $code, 'name' => $code]);
        }
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Dashboard season']);
        foreach ([['O', 'B'], ['B', 'B'], ['B', 'P'], [], ['inactive']] as $codes) {
            $invoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'season_id' => $season]);
            foreach ($codes as $code) {
                $this->db->table('clubora.invoice_lines')->insert(['tenant_id' => $this->own->id, 'invoice_id' => $invoice, 'status_id' => $statuses[$code === 'inactive' ? 'O' : $code], 'net_amount' => 10, 'gross_amount' => 12, 'active' => $code !== 'inactive']);
            }
        }
        $service = app(DashboardService::class);
        $data = collect($service->invoicesByStatus($this->actor, $this->own))->keyBy('status');
        $this->assertEquals(1, $data['Offen']['total']);
        $this->assertEquals(1, $data['Bezahlt']['total']);
        $this->assertEquals(1, $data['Teilweise']['total']);
        $this->assertEquals(2, $data['Ohne Position']['total']);
        $seasonData = $service->invoicesBySeason($this->actor, $this->own);
        $this->assertCount(1, $seasonData);
        $this->assertEquals(5, $seasonData[0]['total']);
        $this->assertEquals(60, $seasonData[0]['amount']);
        $this->assertEquals(10, $seasonData[0]['open_amount']);
        foreach ([InvoicesByStatus::class, InvoicesBySeason::class, OpenInvoicesTable::class, PendingExpensesTable::class] as $widget) {
            Livewire::test($widget)->assertSuccessful();
        }
        $this->expectException(HttpException::class);
        $service->invoicesByStatus($this->actor, $this->other);
    }

    public function test_editable_status_colors_are_normalized_scoped_and_revision_checked(): void
    {
        $service = app(StatusStyleService::class);
        $fallback = StatusAppearance::hex('Offen');
        $record = $service->save($this->actor, $this->own, null, ['name' => '  OFFEN ', 'color' => '#123456', 'active' => true]);
        $this->assertSame('#123456', StatusAppearance::hex('offen'));
        $this->assertSame('#123456', StatusAppearance::hex('  Offen  '));
        $this->assertSame(Color::hex('#123456'), StatusAppearance::palette('Offen'));
        $this->db->table('clubora.status_styles')->insert(['tenant_id' => $this->other->id, 'name' => 'Offen', 'value_key' => 'offen', 'color' => '#abcdef']);
        $this->assertSame('#123456', StatusAppearance::hex('Offen'));
        foreach ([['name' => 'offen', 'color' => '#999999', 'active' => true], ['name' => 'Unsafe', 'color' => 'red;display:none', 'active' => true]] as $bad) {
            try {
                $service->save($this->actor, $this->own, null, $bad);
                $this->fail('Invalid color accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        Livewire::test(ManageStyles::class)->assertCountTableRecords(1);
        $service->save($this->actor, $this->own, $record->id, ['name' => 'Offen', 'color' => '#123456', 'active' => false, 'revision' => 1]);
        $this->assertSame($fallback, StatusAppearance::hex('Offen'));
        try {
            $service->save($this->actor, $this->own, $record->id, ['name' => 'Offen', 'color' => '#123456', 'active' => true, 'revision' => 1]);
            $this->fail('Stale revision accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        [$trainer] = $this->portalActor('trainer');
        try {
            $service->save($trainer, $this->own, null, ['name' => 'Denied', 'color' => '#123456', 'active' => true]);
            $this->fail('Trainer edited color');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_facility_forms_write_and_invalid_signage_selections_are_readable(): void
    {
        $ids = $this->facilityFixture();
        Livewire::test(ManageCompetitions::class)
            ->callTableAction('create', data: ['plays_on' => now('Europe/Zurich')->toDateString(), 'starts_at' => '19:15', 'host_name' => 'Created host', 'guest_name' => 'Created guest', 'field_id' => $ids['field']['H'], 'active' => true, 'show_on_signage' => true])->assertHasNoTableActionErrors();
        $record = Competition::where('tenant_id', $this->own->id)->firstOrFail();
        Livewire::test(ManageCompetitions::class)->callTableAction('edit', $record, data: ['host_room_id' => $ids['room']['1']])->assertHasNoTableActionErrors();
        $this->assertEquals($ids['room']['1'], $record->fresh()->host_room_id);
        $this->assertEquals(2, $record->fresh()->revision);
        Livewire::test(SignagePreview::class)->set('date', '')->assertSee('Bitte ein gültiges Datum');
        Livewire::test(SignagePreview::class)->set('periodId', (string) $ids['field']['H'])->assertSee('Bitte ein gültiges Datum');
    }

    private function facilityFixture(): array
    {
        $ids = [];
        foreach (['field' => ['H', 'K', 'T'], 'room' => ['1', '2'], 'side' => ['L', 'R'], 'period' => ['S'], 'weekday' => [(string) (now('Europe/Zurich')->dayOfWeek + 1)]] as $category => $codes) {
            foreach ($codes as $code) {
                $ids[$category][$code] = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => $category, 'code' => $code, 'name' => $category.' '.$code]);
            }
        }
        foreach (['O' => false, 'G' => true] as $code => $blocked) {
            $ids['status'][$code] = $this->db->table('clubora.facility_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => $code, 'name' => $blocked ? 'Gesperrt' : 'Offen', 'blocks_training' => $blocked]);
        }
        foreach ($ids['field'] as $field) {
            $this->db->table('clubora.field_availabilities')->insert(['tenant_id' => $this->own->id, 'field_id' => $field, 'status_id' => $ids['status']['O']]);
        }

        return $ids;
    }

    public function test_facility_import_is_repeatable_without_rewriting_edits(): void
    {
        $this->assertSame(['already_imported' => true], app(FacilityImporter::class)->run());
    }

    public function test_competition_edit_preserves_source_identity_and_audits_revision(): void
    {
        $ids = $this->facilityFixture();
        $id = $this->db->table('clubora.competitions')->insertGetId(['tenant_id' => $this->own->id, 'plays_on' => now()->toDateString(), 'host_name' => 'Fixture host', 'guest_name' => 'Fixture guest', 'association_match_id' => 123, 'source' => 'SFV']);
        $service = app(FacilityService::class);
        $data = ['active' => true, 'revision' => 1, 'plays_on' => now()->toDateString(), 'starts_at' => '20:15', 'host_name' => 'Fixture host', 'guest_name' => 'Fixture guest', 'show_on_signage' => true, 'host_room_id' => $ids['room']['1'], 'field_id' => $ids['field']['H']];
        $record = $service->save($this->actor, $this->own, Competition::class, $id, $data);
        $this->assertSame(123, $record->association_match_id);
        $this->assertSame('SFV', $record->source);
        $this->assertEquals($ids['room']['1'], $record->host_room_id);
        $this->assertEquals(2, $record->revision);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'competitions')->count());
        foreach ([$data, array_replace($data, ['revision' => 2, 'association_match_id' => null]), array_replace($data, ['revision' => 2, 'field_id' => $ids['room']['1']])] as $bad) {
            try {
                $service->save($this->actor, $this->own, Competition::class, $id, $bad);
                $this->fail('Invalid edit accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertEquals(2, $record->fresh()->revision);
    }

    public function test_facility_permissions_and_cross_tenant_lookups_are_enforced(): void
    {
        $ids = $this->facilityFixture();
        $other = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->other->id, 'category' => 'field', 'code' => 'OTHER', 'name' => 'Foreign field']);
        $service = app(FacilityService::class);
        $data = ['active' => true, 'field_id' => $other, 'status_id' => $ids['status']['O']];
        try {
            $service->save($this->actor, $this->own, FieldAvailability::class, null, $data);
            $this->fail('Cross tenant field accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('field_id', $e->errors());
        }
        $data['field_id'] = $ids['field']['H'];
        try {
            $service->save($this->actor, $this->own, FieldAvailability::class, null, $data);
            $this->fail('Duplicate field accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('field_id', $e->errors());
        }
        foreach (['member', 'trainer', 'technical_lead'] as $role) {
            [$actor] = $this->portalActor($role);
            try {
                $service->save($actor, $this->own, FieldAvailability::class, null, $data);
                $this->fail('Unauthorized mutation');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            try {
                app(SignageService::class)->preview($actor, $this->own, ['date' => now()->toDateString()]);
                $this->fail('Unauthorized preview');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_competition_filters_search_and_saved_view_remain_combined(): void
    {
        $ids = $this->facilityFixture();
        $records = [];
        foreach (['today', 'past', 'other-field', 'other-name'] as $type) {
            $id = $this->db->table('clubora.competitions')->insertGetId(['tenant_id' => $this->own->id, 'plays_on' => $type === 'past' ? now('Europe/Zurich')->subDay()->toDateString() : now('Europe/Zurich')->toDateString(), 'host_name' => $type === 'other-name' ? 'Notmatching' : 'Searchfixture', 'guest_name' => 'Guest', 'field_id' => $ids['field'][$type === 'other-field' ? 'K' : 'H']]);
            $records[$type] = Competition::findOrFail($id);
        }
        $page = Livewire::test(ManageCompetitions::class)->assertCountTableRecords(3)
            ->filterTable('field_id', $ids['field']['H'])->searchTable('Searchfixture')->assertCountTableRecords(1)->assertCanSeeTableRecords([$records['today']]);
        $page->filterTable('date', ['from' => now('Europe/Zurich')->toDateString(), 'until' => now('Europe/Zurich')->addDay()->toDateString()])->assertCountTableRecords(1);
        $page->callAction('saveView', data: ['name' => 'Competition fixture', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ManageCompetitions::class)->assertCountTableRecords(1)->assertSet('tableSearch', 'Searchfixture');
        $page->filterTable('date', ['from' => 'invalid-date', 'until' => null])->assertCountTableRecords(0)
            ->callAction('saveView', data: ['name' => 'Invalid filter', 'is_default' => false])->assertHasActionErrors(['view_id']);
    }

    public function test_signage_scope_dates_visibility_and_escaped_content(): void
    {
        $this->facilityFixture();
        foreach (['today', 'hidden', 'inactive', 'past', 'foreign'] as $type) {
            $this->db->table('clubora.competitions')->insert(['tenant_id' => $type === 'foreign' ? $this->other->id : $this->own->id, 'plays_on' => $type === 'past' ? now('Europe/Zurich')->subDay()->toDateString() : now('Europe/Zurich')->toDateString(), 'host_name' => $type, 'guest_name' => 'Guest', 'show_on_signage' => $type !== 'hidden', 'active' => $type !== 'inactive']);
        }
        app(FacilityService::class)->save($this->actor, $this->own, SignageNotice::class, null, ['active' => true, 'shows_on' => now('Europe/Zurich')->toDateString(), 'title' => 'Escaped notice', 'body' => '<script>alert(1)</script>']);
        $data = app(SignageService::class)->preview($this->actor, $this->own, ['date' => now('Europe/Zurich')->toDateString()]);
        $this->assertSame(['today'], $data['competitions']->pluck('host_name')->all());
        $this->assertCount(1, $data['notices']);
        $this->assertCount(3, $data['fields']);
        Livewire::test(SignagePreview::class)->assertSee('Escaped notice')->assertDontSeeHtml('<script>alert(1)</script>');
        foreach ([ManageNotices::class, ManageFields::class, ManageStatuses::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
    }

    public function test_signage_training_variants_follow_field_status_and_day(): void
    {
        $ids = $this->facilityFixture();
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Facilities season', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Facilities team']);
        $ts = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'season_id' => $season, 'team_id' => $team, 'label' => 'Facilities team / season']);
        foreach (['all_fields', 'without_main', 'synthetic_only'] as $flag) {
            $this->db->table('clubora.training_slots')->insert(['tenant_id' => $this->own->id, 'team_season_id' => $ts, 'period_id' => $ids['period']['S'], 'weekday_id' => array_values($ids['weekday'])[0], 'starts_at' => '18:00:00', 'ends_at' => '19:00:00', 'hall' => $flag, $flag => true]);
        }
        $service = app(SignageService::class);
        $input = ['date' => now('Europe/Zurich')->toDateString(), 'period_id' => $ids['period']['S']];
        foreach (['all_fields' => null, 'without_main' => 'H', 'synthetic_only' => 'T'] as $expected => $close) {
            if ($close) {
                $this->db->table('clubora.field_availabilities')->where('tenant_id', $this->own->id)->where('field_id', $ids['field'][$close])->update(['status_id' => $ids['status']['G']]);
            }
            $data = $service->preview($this->actor, $this->own, $input);
            $this->assertSame($expected, $data['training_variant']);
            $this->assertSame([$expected], $data['trainings']->pluck('hall')->all());
        }
        $this->assertCount(0, $service->preview($this->actor, $this->own, array_replace($input, ['date' => now('Europe/Zurich')->addDay()->toDateString()]))['trainings']);
        $this->db->table('clubora.teams')->where('id', $team)->update(['active' => false]);
        $this->assertCount(0, $service->preview($this->actor, $this->own, $input)['trainings']);
    }

    private function membershipReportFixture(): array
    {
        $status = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture-current', 'name' => 'Mitglied', 'restores_membership' => true]);
        $type = $this->db->table('clubora.member_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture-playing', 'name' => 'Spielend', 'team_required' => true]);
        $passive = $this->db->table('clubora.member_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture-passive', 'name' => 'Passiv', 'passive_membership' => true]);
        $members = [];
        foreach (['missing', 'assigned', 'old', 'inactive', 'honorary', 'free_membership', 'supporter', 'sponsor', 'exited', 'archived', 'passive'] as $name) {
            $values = ['tenant_id' => $this->own->id, 'first_name' => $name, 'last_name' => 'Report fixture', 'status_id' => $status, 'type_id' => $name === 'passive' ? $passive : $type];
            if (in_array($name, ['honorary', 'free_membership', 'supporter', 'sponsor'])) {
                $values[$name] = true;
            }
            if ($name === 'exited') {
                $values['exit_date'] = now('Europe/Zurich')->toDateString();
            }
            if ($name === 'archived') {
                $values['archived_at'] = now();
            }
            $members[$name] = Member::findOrFail($this->db->table('clubora.members')->insertGetId($values));
        }
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Report current', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()]);
        $old = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Report old']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Report team']);
        foreach (['assigned' => $season, 'old' => $old, 'inactive' => $season] as $name => $sid) {
            $ts = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'season_id' => $sid, 'team_id' => $team, 'label' => $name]);
            $this->db->table('clubora.team_players')->insert(['tenant_id' => $this->own->id, 'member_id' => $members[$name]->id, 'team_season_id' => $ts, 'active' => $name !== 'inactive']);
        }

        return [$members, $season, $old, $type];
    }

    public function test_membership_report_exports_reapply_eligibility_and_preserve_search(): void
    {
        [$members, $season] = $this->membershipReportFixture();
        $service = app(ReportExportService::class);
        foreach (['unassigned' => ['missing', 'old', 'inactive'], 'passive' => ['passive']] as $kind => $expected) {
            ob_start();
            $service->download($this->actor, $this->own, $kind, Member::withoutGlobalScopes()->orderBy('first_name'), $season)->sendContent();
            $csv = ob_get_clean();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            foreach ($members as $name => $member) {
                if (in_array($name, $expected)) {
                    $this->assertStringContainsString($member->display_name, $csv);
                } else {
                    $this->assertStringNotContainsString($member->display_name, $csv);
                }
            }
            $this->assertStringNotContainsString('FOREIGN-MEMBER', $csv);
            $this->assertStringNotContainsString('Geburtsdatum', $csv);
            if ($kind === 'unassigned') {
                $this->assertStringContainsString('Report current', $csv);
            }
        }
        Livewire::test(ListUnassignedMembers::class)->searchTable('missing')
            ->callAction('saveView', data: ['name' => 'Export selection', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ListUnassignedMembers::class)->assertSet('tableSearch', 'missing')
            ->callTableAction('export')->assertHasNoActionErrors()
            ->assertFileDownloaded('mitglieder-ohne-mannschaft-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $audit = json_decode($this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'report_exports')->orderByDesc('id')->value('after_value'), true);
        $this->assertSame(1, $audit['row_count']);
        $this->assertStringNotContainsString('missing', json_encode($audit));
        Livewire::test(ListPassiveMembers::class)->searchTable('not-found')->callTableAction('export')
            ->assertFileDownloaded('passivmitglieder-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
    }

    public function test_membership_export_invalid_season_and_portal_roles_are_rejected(): void
    {
        [$members, $season] = $this->membershipReportFixture();
        $foreign = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->other->id, 'name' => 'Foreign']);
        foreach ([null, $foreign, ['invalid']] as $invalid) {
            ob_start();
            app(ReportExportService::class)->download($this->actor, $this->own, 'unassigned', Member::query(), $invalid)->sendContent();
            $csv = ob_get_clean();
            $this->assertStringNotContainsString('Report fixture', $csv);
        }
        [$trainer] = $this->portalActor('trainer');
        $this->expectException(HttpException::class);
        app(ReportExportService::class)->download($trainer, $this->own, 'passive', Member::query());
    }

    public function test_membership_reports_preserve_rules_and_combine_saved_filters(): void
    {
        [$members, $season] = $this->membershipReportFixture();
        $service = app(MembershipReportService::class);
        $this->assertSame($season, $service->currentSeason($this->own));
        $this->assertEqualsCanonicalizing([$members['missing']->id, $members['old']->id, $members['inactive']->id], $service->withoutTeam($service->teamCandidates($this->actor, $this->own), $this->own, $season)->pluck('id')->all());
        $this->assertSame([$members['passive']->id], $service->passive($this->actor, $this->own)->pluck('id')->all());
        $page = Livewire::test(ListUnassignedMembers::class)
            ->assertCountTableRecords(3)->searchTable('missing')->assertCanSeeTableRecords([$members['missing']])->assertCountTableRecords(1);
        $page->callAction('saveView', data: ['name' => 'Missing fixture', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ListUnassignedMembers::class)->assertCountTableRecords(1)->assertSet('tableSearch', 'missing');
        Livewire::test(ListPassiveMembers::class)->assertCanSeeTableRecords([$members['passive']])->assertCountTableRecords(1);
    }

    public function test_membership_report_season_scope_and_role_are_fail_closed(): void
    {
        [$members, $season] = $this->membershipReportFixture();
        $foreign = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->other->id, 'name' => 'Foreign']);
        $page = Livewire::test(ListUnassignedMembers::class);
        $page->filterTable('season', $foreign)->assertCountTableRecords(0);
        $page->filterTable('season', null)->assertCountTableRecords(0);
        $this->db->table('clubora.seasons')->where('id', $season)->update(['starts_on' => null]);
        $this->assertNull(app(MembershipReportService::class)->currentSeason($this->own));
        $this->get('/clubunio/'.$this->other->slug.'/unassigned-members')->assertNotFound();
        [$trainer] = $this->portalActor('trainer');
        $this->expectException(HttpException::class);
        app(MembershipReportService::class)->passive($trainer, $this->own);
    }

    public function test_member_type_report_flags_are_editable_and_audited(): void
    {
        [$members, $season, $old, $type] = $this->membershipReportFixture();
        Livewire::test(ManageMemberTypes::class)
            ->callTableAction('edit', MemberType::findOrFail($type), data: ['name' => 'New meaning', 'sort_order' => 0, 'active' => true, 'team_required' => false, 'passive_membership' => true])->assertHasNoTableActionErrors();
        $this->assertSame(0, app(MembershipReportService::class)->teamCandidates($this->actor, $this->own)->count());
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'member_types')->count());
        $this->assertTrue(app(MembershipReportService::class)->passive($this->actor, $this->own)->whereKey($members['missing']->id)->exists());
    }

    public function test_membership_report_deleted_team_and_ambiguous_season(): void
    {
        [$members, $season] = $this->membershipReportFixture();
        $service = app(MembershipReportService::class);
        $ts = $this->db->table('clubora.team_players')->where('member_id', $members['assigned']->id)->value('team_season_id');
        $this->db->table('clubora.team_seasons')->where('id', $ts)->update(['active' => false]);
        $this->assertTrue($service->withoutTeam($service->teamCandidates($this->actor, $this->own), $this->own, $season)->whereKey($members['assigned']->id)->exists());
        $this->db->table('clubora.seasons')->insert(['tenant_id' => $this->own->id, 'name' => 'Overlapping', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString()]);
        $this->assertNull($service->currentSeason($this->own));
        Livewire::test(ListUnassignedMembers::class)->assertCountTableRecords(0)->assertSee('Keine Saison ausgewählt');
    }

    public function test_report_pages_and_combined_season_contribution_filters(): void
    {
        foreach (['player-reports', 'staff-reports', 'payment-reports', 'open-invoices'] as $slug) {
            $this->get('/'.$slug)->assertOk();
            $this->get('/clubunio/'.$this->other->slug.'/'.$slug)->assertNotFound();
        }
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test season']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test team']);
        $ts = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Test assignment']);
        $id = $this->db->table('clubora.team_players')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $ts]);
        Livewire::test(ListPlayers::class)
            ->filterTable('season', $season)->filterTable('without_contribution', true)
            ->assertCanSeeTableRecords([TeamPlayer::findOrFail($id)])
            ->searchTable('not-existing')->assertCountTableRecords(0);
        Livewire::test(ExpensesBySeason::class)->assertSee('Spesenbeträge nach Saison');
        $this->assertFalse(PlayerReportResource::canCreate());
        $this->assertFalse(StaffReportResource::canCreate());
        $this->assertFalse(PaymentReportResource::canCreate());
    }

    public function test_open_invoice_dashboard_uses_positions_and_legacy_amount_rules(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $open = $this->db->table('clubora.invoice_line_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'O', 'name' => 'Offen']);
        $paid = $this->db->table('clubora.invoice_line_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'B', 'name' => 'Bezahlt']);
        $invoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id]);
        foreach ([[40, 50, $open, true], [0, 30, $open, true], [999, 999, $paid, true], [999, 999, $open, false]] as [$net, $gross, $status, $active]) {
            $this->db->table('clubora.invoice_lines')->insert(['tenant_id' => $this->own->id, 'invoice_id' => $invoice, 'status_id' => $status, 'net_amount' => $net, 'gross_amount' => $gross, 'active' => $active]);
        }
        $service = app(DashboardService::class);
        $totals = $service->openSummary($this->actor, $this->own);
        $this->assertSame(1, $totals['count']);
        $this->assertSame(70.0, (float) $totals['amount']);
        Livewire::test(ListOpenInvoices::class)
            ->assertCanSeeTableRecords([Invoice::findOrFail($invoice)])->searchTable('absent fixture')->assertCountTableRecords(0);
        $this->db->table('clubora.invoices')->where('id', $invoice)->update(['active' => false]);
        $this->assertSame(0, $service->openSummary($this->actor, $this->own)['count']);
        $this->expectException(HttpException::class);
        $service->openSummary($this->actor, $this->other);
    }

    public function test_age_dashboard_boundaries_and_departed_members(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');
        try {
            foreach (['2020-10-12', '2013-10-12', '2008-10-12', '1996-10-12', '1976-10-12', '2027-01-01'] as $birth) {
                $this->db->table('clubora.members')->insert(['tenant_id' => $this->own->id, 'first_name' => 'Age fixture', 'birth_date' => $birth]);
            }
            $this->db->table('clubora.members')->insert(['tenant_id' => $this->own->id, 'birth_date' => '2025-01-01', 'exit_date' => '2026-10-12']);
            $counts = app(DashboardService::class)->ages($this->actor, $this->own);
            $this->assertSame(['0-5' => 0, '6-12' => 1, '13-17' => 1, '18-29' => 1, '30-49' => 1, '50+' => 1, 'Unbekannt' => 2], $counts);
            Livewire::test(MembersByAge::class)->assertSee('Aktive Mitglieder nach Alter');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function reviewFixture(): array
    {
        $statuses = [];
        foreach (['submitted', 'review', 'trial', 'accepted', 'rejected', 'pool'] as $key) {
            $statuses[$key] = $this->db->table('clubora.registration_statuses')->insertGetId([
                'tenant_id' => $this->own->id, 'code' => $key, 'name' => $key, 'workflow_key' => $key, 'active' => true,
            ]);
        }
        $r = new Registration;
        $r->forceFill(['tenant_id' => $this->own->id, 'legacy_id' => 876543,
            'first_name' => 'Registration Fixture', 'status_id' => $statuses['submitted'], 'active' => true])->save();

        return [$r->refresh(), $statuses];
    }

    public function test_registration_review_audits_holds_events_and_rejects_stale_changes(): void
    {
        [$r, $statuses] = $this->reviewFixture();
        $service = app(RegistrationReviewService::class);
        $input = ['revision' => 1, 'status_id' => $statuses['review'], 'reason' => 'Pruefung begonnen'];
        $result = $service->review($this->actor, $this->own, $r->id, $input);
        $this->assertSame(2, $result->revision);
        $this->assertSame('Registration Fixture', $result->first_name);
        $this->assertSame(0, $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->count());
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('object_type', 'registrations')->where('object_id', $r->id)->count());
        $service->review($this->actor, $this->own, $r->id, array_replace($input, ['revision' => 2]));
        $this->assertSame(2, $r->fresh()->revision);
        $service->review($this->actor, $this->own, $r->id, array_replace($input, ['revision' => 2, 'status_id' => $statuses['pool']]));
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->sole();
        $this->assertSame('registration.pool', $event->event_key);
        $this->assertSame('held', $event->delivery_state);
        $this->expectException(ValidationException::class);
        $service->review($this->actor, $this->own, $r->id, $input);
    }

    public function test_registration_review_blocks_unsafe_acceptance_and_foreign_or_unassigned_teams(): void
    {
        [$r, $statuses] = $this->reviewFixture();
        $service = app(RegistrationReviewService::class);
        foreach ([['status_id' => $statuses['accepted']], ['status_id' => $statuses['trial']],
            ['status_id' => $statuses['review'], 'team_season_id' => -10],
            ['status_id' => $statuses['review'], 'technical_lead_member_id' => $this->db->table('clubora.members')->where('tenant_id', $this->other->id)->value('id')],
            ['status_id' => $statuses['review'], 'first_name' => 'tampered']] as $changes) {
            try {
                $service->review($this->actor, $this->own, $r->id, $changes + ['revision' => 1, 'reason' => 'Controlled test']);
                $this->fail('Unsafe review accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(1, $r->fresh()->revision);
        $this->assertSame(0, $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->count());
    }

    public function test_registration_review_tl_assignment_requires_active_identity_and_backoffice(): void
    {
        [$r, $statuses] = $this->reviewFixture();
        [$tl, $member] = $this->portalActor('technical_lead');
        $service = app(RegistrationReviewService::class);
        $this->assertTrue($service->leaders($this->own)->whereKey($member->id)->exists());
        $result = $service->review($this->actor, $this->own, $r->id, [
            'revision' => 1, 'status_id' => $statuses['review'], 'technical_lead_member_id' => $member->id, 'reason' => 'TL prueft Anmeldung',
        ]);
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->sole();
        $this->assertSame('registration.review', $event->event_key);
        $this->assertEquals($member->id, $event->technical_lead_member_id);
        $this->assertEquals($member->id, $result->technical_lead_member_id);
        $service->review($tl, $this->own, $r->id, ['revision' => 2, 'status_id' => $statuses['pool'], 'reason' => 'Assigned TL review']);
        $this->assertEquals($statuses['pool'], $r->fresh()->status_id);
        $this->expectException(HttpException::class);
        $service->review($tl, $this->own, $r->id, ['revision' => 3, 'status_id' => $statuses['review'], 'technical_lead_member_id' => null, 'reason' => 'Cannot remove assignment']);
    }

    public function test_registration_review_action_and_tenant_isolation(): void
    {
        [$r, $statuses] = $this->reviewFixture();
        Livewire::test(ListRegistrations::class)->mountTableAction('review', $r)
            ->setTableActionData(['revision' => 1, 'status_id' => $statuses['review'], 'team_season_id' => null,
                'technical_lead_member_id' => null, 'reason' => 'Review through UI'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame(2, $r->fresh()->revision);
        $foreign = new Registration;
        $foreign->forceFill(['tenant_id' => $this->other->id, 'legacy_id' => 876544, 'active' => true])->save();
        $this->expectException(ModelNotFoundException::class);
        app(RegistrationReviewService::class)->review($this->actor, $this->own, $foreign->id,
            ['revision' => 1, 'status_id' => $statuses['review'], 'reason' => 'Foreign registration']);
    }

    private $db;

    private function completeRegistrationFixture(): array
    {
        [$r, $statuses] = $this->reviewFixture();
        $memberStatus = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'active', 'name' => 'Mitglied', 'restores_membership' => true]);
        $type = $this->db->table('clubora.member_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'active', 'name' => 'Aktiv']);
        $billing = $this->db->table('clubora.billing_recipients')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'M', 'name' => 'Mutter', 'email_field' => 'mother_email']);
        $country = $this->db->table('clubora.countries')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'CH', 'name' => 'Schweiz', 'iso3' => 'CHE']);
        $gender = $this->db->table('clubora.genders')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'N', 'name' => 'Neutral']);
        $docType = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'identity', 'name' => 'Ausweis']);
        $details = ['street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '00445', 'mobile' => '000000',
            'country_id' => $country, 'nationality_id' => $country, 'gender_id' => $gender, 'billing_recipient_id' => $billing,
            'mother_email' => 'mother@example.invalid', 'notes' => 'Registration note', 'ahv' => 'SECRET-AHV-FIXTURE'];
        $r->forceFill(['last_name' => 'Applicant', 'birth_date' => '2014-01-02', 'email' => 'applicant@example.invalid', 'city' => 'Sissach',
            'details_complete' => true, 'applicant_encrypted' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR))])->save();
        foreach (['identity-one.pdf', 'identity-two.pdf'] as $file) {
            $this->db->table('clubora.registration_documents')->insert(['tenant_id' => $this->own->id, 'registration_id' => $r->id,
                'kind' => 'identity', 'filename' => $file, 'storage_path' => 'upload/anmeldungen/']);
        }

        return [$r, $statuses, $memberStatus, $type, $docType];
    }

    public function test_registration_acceptance_creates_member_documents_audit_and_committed_mail_event(): void
    {
        [$r, $statuses, $memberStatus, $type, $docType] = $this->completeRegistrationFixture();
        app(MessageTemplateService::class)->seed($this->own);
        $result = app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, [
            'revision' => 1, 'reason' => 'Accepted after review', 'status_id' => $statuses['accepted'], 'member_type_id' => $type, 'document_type_id' => $docType,
        ]);
        $member = $result->member;
        $this->assertNotNull($member);
        $this->assertNull($member->legacy_id);
        $this->assertEquals($memberStatus, $member->status_id);
        $this->assertNull($member->exit_date);
        $this->assertSame('00445', $member->postal_code);
        $this->assertSame('SECRET-AHV-FIXTURE', Crypt::decryptString($member->ahv_encrypted));
        $this->assertSame(2, $this->db->table('clubora.member_documents')->where('member_id', $member->id)->count());
        $this->assertSame(1, $this->db->table('clubora.member_notes')->where('member_id', $member->id)->count());
        $event = $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->sole();
        $this->assertSame('ready', $event->delivery_state);
        $payload = json_decode(Crypt::decryptString($event->payload_encrypted), true);
        $this->assertSame('mother@example.invalid', $payload['intended_to']);
        $this->assertStringNotContainsString('SECRET-AHV', json_encode($result));
        $this->expectException(ValidationException::class);
        app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, [
            'revision' => 1, 'reason' => 'Retry same request', 'status_id' => $statuses['accepted'], 'member_type_id' => $type, 'document_type_id' => $docType,
        ]);
    }

    public function test_registration_reactivates_existing_member_preserves_pass_number_and_clears_exit_date(): void
    {
        [$r, $statuses, $memberStatus, $type, $docType] = $this->completeRegistrationFixture();
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $exit = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'left', 'name' => 'Ausgetreten', 'ends_membership' => true]);
        $member->forceFill(['status_id' => $exit, 'exit_date' => '2020-01-01', 'pass_number' => '123456', 'email' => $r->email])->save();
        $input = ['revision' => 1, 'reason' => 'Confirmed returning member', 'status_id' => $statuses['accepted'], 'member_type_id' => $type, 'document_type_id' => $docType];
        try {
            app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, $input);
            $this->fail('Duplicate was not stopped');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $this->assertSame(1, $r->fresh()->revision);
        $input += ['member_id' => $member->id, 'member_version' => MemberService::version($member)];
        $result = app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, $input);
        $this->assertEquals($member->id, $result->member_id);
        $member->refresh();
        $this->assertEquals($memberStatus, $member->status_id);
        $this->assertNull($member->exit_date);
        $this->assertSame('123456', $member->pass_number);
        $this->assertSame($r->first_name, $member->first_name);
        $this->assertSame('Teststrasse', $member->street);
        $this->assertSame(1, Member::where('tenant_id', $this->own->id)->count());
    }

    public function test_registration_acceptance_rolls_back_all_member_work_when_event_creation_fails(): void
    {
        [$r, $statuses, , $type, $docType] = $this->completeRegistrationFixture();
        $this->mock(RegistrationMessageService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('synthetic failure'));
        });
        try {
            app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, [
                'revision' => 1, 'reason' => 'Atomic acceptance test', 'status_id' => $statuses['accepted'], 'member_type_id' => $type, 'document_type_id' => $docType,
            ]);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic failure', $e->getMessage());
        }
        $this->assertSame(1, Member::where('tenant_id', $this->own->id)->count());
        $this->assertNull($r->fresh()->member_id);
        $this->assertSame(1, $r->fresh()->revision);
        $this->assertSame(0, $this->db->table('clubora.member_documents')->where('tenant_id', $this->own->id)->count());
        $this->assertSame(0, $this->db->table('clubora.member_notes')->where('tenant_id', $this->own->id)->count());
    }

    public function test_registration_tl_portal_scopes_documents_changes_and_notifications(): void
    {
        [$r, $statuses] = $this->reviewFixture();
        [$tl, $member] = $this->portalActor('technical_lead');
        app(MessageTemplateService::class)->seed($this->own);
        $prefix = '/portal/'.$this->own->slug;
        $this->actingAs($tl, 'portal')->get($prefix.'/registrations/'.$r->id)->assertNotFound();
        app(RegistrationReviewService::class)->review($this->actor, $this->own, $r->id, [
            'revision' => 1, 'status_id' => $statuses['review'], 'technical_lead_member_id' => $member->id, 'reason' => 'Assigned by office',
        ]);
        $this->get($prefix.'/registrations/'.$r->id)->assertOk()->assertSee('Registration Fixture')->assertDontSee('applicant_encrypted');
        $notice = $this->db->table('clubora.notifications')->where('account_id', $tl->id)->sole();
        $this->get($prefix.'/notifications')->assertOk()->assertSee('Mitgliederanmeldung zur Prüfung');
        $this->post($prefix.'/notifications/'.$notice->id.'/read')->assertRedirect();
        $this->assertNotNull($this->db->table('clubora.notifications')->where('id', $notice->id)->value('read_at'));
        $this->post($prefix.'/registrations/'.$r->id, ['revision' => 2, 'status_id' => $statuses['pool'], 'reason' => 'No capacity currently'])->assertRedirect();
        $this->assertEquals($statuses['pool'], $r->fresh()->status_id);
        $this->db->table('clubora.account_member_links')->where('account_id', $tl->id)->update(['state' => 'revoked']);
        $this->get($prefix.'/registrations/'.$r->id)->assertForbidden();
        $this->get($prefix.'/notifications')->assertForbidden();
    }

    public function test_registration_data_edit_encrypted_and_foreign_lookup_rejected(): void
    {
        [$r] = $this->completeRegistrationFixture();
        $service = app(RegistrationDataService::class);
        $data = $service->formValues($r);
        $this->assertArrayNotHasKey('ahv', $data);
        $result = $service->save($this->actor, $this->own, $r->id, array_replace($data, ['city' => 'Updated city']));
        $this->assertSame('Updated city', $result->city);
        $this->assertSame('SECRET-AHV-FIXTURE', $service->values($result)['ahv']);
        $this->assertStringNotContainsString('mother@example.invalid', $result->applicant_encrypted);
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, $r->id, ['revision' => 2, 'country_id' => -10]);
    }

    public function test_registration_applicant_action_saves_without_exposing_sensitive_source(): void
    {
        [$r] = $this->completeRegistrationFixture();
        Livewire::test(ListRegistrations::class)->mountTableAction('applicant', $r)
            ->assertDontSee('SECRET-AHV-FIXTURE')
            ->setTableActionData(['city' => 'Form updated'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Form updated', $r->fresh()->city);
        $this->assertSame(2, $r->fresh()->revision);
    }

    public function test_registration_mail_never_sends_inside_a_business_transaction(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true]);
        $this->expectException(\LogicException::class);
        app(RegistrationMessageService::class)->dispatch();
    }

    public function test_registration_notifications_are_private_to_the_recipient(): void
    {
        [$a] = $this->portalActor('technical_lead');
        [$b] = $this->portalActor('technical_lead');
        $id = $this->db->table('clubora.notifications')->insertGetId([
            'tenant_id' => $this->own->id, 'account_id' => $b->id, 'title' => 'Private notification fixture',
            'body' => 'Private content', 'object_type' => 'registration', 'object_id' => 1,
        ]);
        $prefix = '/portal/'.$this->own->slug.'/notifications';
        $this->actingAs($a, 'portal')->get($prefix)->assertOk()->assertDontSee('Private notification fixture');
        $this->post($prefix.'/'.$id.'/read')->assertNotFound();
        $this->post($prefix.'/read-all')->assertRedirect();
        $this->assertNull($this->db->table('clubora.notifications')->where('id', $id)->value('read_at'));
    }

    private Account $actor;

    private function expenseFixture(): array
    {
        [$owner,$member] = $this->portalActor('trainer');
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test season']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test team', 'laundry_allowance' => '35.00']);
        $ts = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Test team / season']);
        $this->db->table('clubora.team_staff')->insert(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $ts]);
        $bank = $this->db->table('clubora.member_bank_accounts')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $round = $this->db->table('clubora.expense_rounds')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'test', 'name' => 'Test round']);
        $statuses = [];
        foreach (['O', 'F', 'Z', 'B', 'R'] as $code) {
            $statuses[$code] = $this->db->table('clubora.expense_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => $code, 'name' => $code]);
        }
        $type = $this->db->table('clubora.expense_types')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Dressreinigung', 'amount_source' => 'team_laundry']);
        $e = app(ExpenseService::class)->save($owner, $this->own, null, ['member_id' => $member->id, 'team_season_id' => $ts, 'round_id' => $round, 'bank_account_id' => $bank]);

        return [$e, $owner, $member, $statuses, $type];
    }

    public function test_expense_laundry_default_and_manual_variance_survive_create_edit(): void
    {
        [$e,$owner,,$statuses,$type] = $this->expenseFixture();
        $service = app(ExpenseService::class);
        $line = $service->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 1, 'expense_type_id' => $type, 'amount' => null]);
        $this->assertSame('35.0000', $line->amount);
        $this->assertSame('35.0000', $line->suggested_amount);
        $this->assertTrue($line->suggestion_accepted);
        $line = $service->saveLine($owner, $this->own, $e->id, $line->id, ['expense_revision' => 2, 'revision' => 1, 'amount' => '36']);
        $this->assertFalse($line->suggestion_accepted);
        $line = $service->saveLine($owner, $this->own, $e->id, $line->id, ['expense_revision' => 3, 'revision' => 2, 'amount' => '35.00']);
        $this->assertTrue($line->suggestion_accepted);
        $line2 = $service->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 4, 'expense_type_id' => $type, 'amount' => '20']);
        $this->assertFalse($line2->suggestion_accepted);
        $this->assertSame('35.0000', $line2->suggested_amount);
        $this->expectException(ValidationException::class);
        $service->saveLine($owner, $this->own, $e->id, $line2->id, ['expense_revision' => 5, 'revision' => 1, 'suggestion_accepted' => true]);
    }

    public function test_expense_owner_release_tl_payment_and_restrictions(): void
    {
        [$e,$owner,,$statuses,$type] = $this->expenseFixture();
        [$tl,$tlMember] = $this->portalActor('technical_lead', true);
        $this->db->table('clubora.technical_leads')->insert(['tenant_id' => $this->own->id, 'member_id' => $tlMember->id, 'team_season_id' => $e->team_season_id]);
        $service = app(ExpenseService::class);
        app(MessageTemplateService::class)->seed($this->own);
        $service->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 1, 'expense_type_id' => $type, 'amount' => '35']);
        $released = $service->transition($owner, $this->own, $e->id, ['revision' => 2, 'status_id' => $statuses['F'], 'reason' => 'Owner submits claim']);
        $this->assertNotNull($released->released_on);
        $this->assertFalse($service->editable($owner, $this->own, $released));
        $this->assertSame(['Z', 'R'], $service->targets($tl, $this->own, $released));
        $this->assertSame(1, $this->db->table('clubora.notifications')->where('account_id', $tl->id)->where('object_type', 'expense')->count());
        try {
            $service->transition($tl, $this->own, $e->id, ['revision' => 3, 'status_id' => $statuses['O'], 'reason' => 'Not allowed to reopen']);
            $this->fail('TL reopened claim');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $paid = $service->transition($tl, $this->own, $e->id, ['revision' => 3, 'status_id' => $statuses['Z'], 'reason' => 'TL approves for payment']);
        $this->assertNotNull($paid->payment_released_on);
        $this->assertSame(1, $this->db->table('clubora.notifications')->where('account_id', $owner->id)->where('object_type', 'expense')->count());
        $this->assertSame(2, $this->db->table('clubora.expense_events')->where('expense_id', $e->id)->count());
        $this->assertNull($paid->bexio_bill_id);
    }

    public function test_expense_foreign_claim_and_bank_are_rejected(): void
    {
        [$e,$owner] = $this->expenseFixture();
        [$stranger,$strangerMember] = $this->portalActor('trainer', true);
        $service = app(ExpenseService::class);
        $this->assertFalse($service->visible($stranger, $this->own)->whereKey($e->id)->exists());
        $bank = $this->db->table('clubora.member_bank_accounts')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $strangerMember->id, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $this->expectException(ValidationException::class);
        $service->save($owner, $this->own, $e->id, ['revision' => 1, 'bank_account_id' => $bank]);
    }

    public function test_expense_view_actions_render_and_change_state(): void
    {
        [$e,$owner,,$statuses,$type] = $this->expenseFixture();
        app(ExpenseService::class)->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 1, 'expense_type_id' => $type, 'amount' => '35']);
        Livewire::test(ViewExpense::class, ['record' => $e->id])
            ->callAction('transition', data: ['revision' => 2, 'status_id' => $statuses['F'], 'reason' => 'Admin verifies release'])
            ->assertHasNoActionErrors();
        $this->assertEquals($statuses['F'], $e->fresh()->status_id);
    }

    private Tenant $own;

    public function test_expense_portal_shows_only_authorized_claims_and_protects_documents(): void
    {
        [$e,$owner,,$statuses,$type] = $this->expenseFixture();
        $line = app(ExpenseService::class)->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 1, 'expense_type_id' => $type, 'amount' => '35']);
        $line->forceFill(['storage_reference' => 'fixture.pdf', 'filename' => 'fixture.pdf'])->save();
        config(['clubunio-integrations.azure.tenant_slug' => $this->own->slug]);
        $this->mock(AzureDocumentReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->with('azure.blob://files/', 'fixture.pdf')->andReturn("%PDF-1.4\nfixture");
        });
        $prefix = '/portal/'.$this->own->slug;
        $this->actingAs($owner, 'portal')->get($prefix.'/expenses?q=Test&status='.$statuses['O'])->assertOk()->assertSee('Test team / season');
        $this->get($prefix.'/expenses/new')->assertOk()->assertSee('Bankkonto')->assertDontSee('CH9300762011623852957');
        $this->get($prefix.'/expenses/'.$e->id)->assertOk()->assertSee('Neue Position')->assertSee('Prüfstatus grün');
        $this->get($prefix.'/expense-documents/'.$line->id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        [$stranger] = $this->portalActor('trainer', true);
        $this->actingAs($stranger, 'portal')->get($prefix.'/expenses/'.$e->id)->assertNotFound();
        $this->get($prefix.'/expense-documents/'.$line->id)->assertNotFound();
        $this->post($prefix.'/expenses/'.$e->id.'/status', ['revision' => 2, 'status_id' => $statuses['F'], 'reason' => 'Forbidden stranger request'])->assertNotFound();
    }

    public function test_expense_release_requires_receipt_and_stale_line_is_not_saved(): void
    {
        [$e,$owner,,$statuses,$type] = $this->expenseFixture();
        $service = app(ExpenseService::class);
        $line = $service->saveLine($owner, $this->own, $e->id, null, ['expense_revision' => 1, 'expense_type_id' => $type, 'amount' => '35']);
        $this->db->table('clubora.expense_types')->where('id', $type)->update(['receipt_required' => true]);
        try {
            $service->transition($owner, $this->own, $e->id, ['revision' => 2, 'status_id' => $statuses['F'], 'reason' => 'Receipt is missing here']);
            $this->fail('Claim without mandatory receipt released');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('status_id', $error->errors());
        }
        $this->assertEquals($statuses['O'], $e->fresh()->status_id);
        $this->assertSame(0, $this->db->table('clubora.expense_events')->where('expense_id', $e->id)->count());
        $this->expectException(ValidationException::class);
        $service->saveLine($owner, $this->own, $e->id, $line->id, ['expense_revision' => 1, 'revision' => 1, 'amount' => '1']);
    }

    private Tenant $other;

    public function test_bank_account_own_scope_encryption_checksum_and_duplicate(): void
    {
        [$owner,$member] = $this->portalActor('technical_lead');
        [$other,$otherMember] = $this->portalActor('trainer', true);
        $bankId = $this->db->table('clubora.banks')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture Bank']);
        $service = app(BankAccountService::class);
        $account = $service->save($owner, $this->own, $member->id, null, ['bank_id' => $bankId, 'iban' => 'CH93 0076 2011 6238 5295 7', 'active' => true]);
        $this->assertSame('CH9300762011623852957', Crypt::decryptString($account->iban_encrypted));
        $this->assertStringNotContainsString('CH9300762011623852957', $account->toJson());
        $this->assertFalse($service->visible($other, $this->own)->whereKey($account->id)->exists());
        $audit = $this->db->table('clubora.object_changes')->where('object_type', 'member_bank_accounts')->where('object_id', $account->id)->sole();
        $this->assertStringNotContainsString('CH9300762011623852957', $audit->after_value);
        foreach (['CH9300762011623852958', 'CH9300762011623852957'] as $iban) {
            try {
                $service->save($owner, $this->own, $member->id, null, ['bank_id' => $bankId, 'iban' => $iban, 'active' => true]);
                $this->fail('Invalid or duplicate IBAN accepted');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('iban', $error->errors());
            }
        }
        $this->actingAs($owner, 'portal')->get('/portal/'.$this->own->slug.'/bank-accounts')->assertOk()->assertSee('2957', false)->assertDontSee('CH9300762011623852957');
        $this->expectException(HttpException::class);
        $service->save($owner, $this->own, $otherMember->id, null, ['bank_id' => $bankId, 'iban' => 'CH9300762011623852957', 'active' => true]);
    }

    public function test_bank_account_used_in_claim_cannot_change_payment_identity(): void
    {
        [$e,$owner,$member] = $this->expenseFixture();
        $bankId = $this->db->table('clubora.banks')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Other bank']);
        $this->expectException(ValidationException::class);
        app(BankAccountService::class)->save($owner, $this->own, $member->id, $e->bank_account_id, ['revision' => 1, 'bank_id' => $bankId, 'active' => true]);
    }

    public function test_expense_catalog_forms_create_valid_tenant_scoped_values(): void
    {
        foreach (['expense-types', 'banks', 'expense-rounds', 'expense-statuses', 'game-types'] as $slug) {
            $this->get('/'.$slug)->assertOk();
        }
        Livewire::test(ManageRecords::class)
            ->callTableAction('create', data: ['name' => 'Fixture type', 'code' => 'test-type', 'sort_order' => 0, 'active' => true, 'receipt_required' => true, 'amount_source' => 'team_laundry'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('team_laundry', $this->db->table('clubora.expense_types')->where('tenant_id', $this->own->id)->where('code', 'test-type')->value('amount_source'));
        Livewire::test(\App\ClubUnio\Resources\BankResource\Pages\ManageRecords::class)
            ->callTableAction('create', data: ['name' => 'Fixture bank', 'code' => 'test-bank', 'sort_order' => 0, 'active' => true, 'city' => 'Sissach', 'swift' => ''])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1, $this->db->table('clubora.banks')->where('tenant_id', $this->own->id)->where('code', 'test-bank')->count());
    }

    public function test_invoice_portal_filters_details_and_foreign_ids_are_scoped(): void
    {
        [$account, $member] = $this->portalActor();
        [, $stranger] = $this->portalActor('member', true);
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Invoice season']);
        $status = $this->db->table('clubora.invoice_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'open', 'name' => 'Open fixture']);
        $invoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'season_id' => $season, 'status_id' => $status, 'legacy_id' => 7654321]);
        $hidden = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $stranger->id, 'legacy_id' => 7654322]);
        $this->db->table('clubora.invoice_lines')->insert([
            ['tenant_id' => $this->own->id, 'invoice_id' => $invoice, 'description' => 'Visible position', 'gross_amount' => 100, 'discount' => 10, 'net_amount' => 90, 'active' => true],
            ['tenant_id' => $this->own->id, 'invoice_id' => $invoice, 'description' => 'Inactive position', 'gross_amount' => 200, 'discount' => 0, 'net_amount' => 200, 'active' => false],
        ]);
        $base = '/portal/'.$this->own->slug.'/invoices';
        $this->actingAs($account, 'portal')->get($base.'?q=OWN&status='.$status.'&season='.$season)
            ->assertOk()->assertSee('7654321')->assertDontSee('7654322')->assertSee('90.00')->assertSee('value="OWN"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($base.'?q=7654321&status='.$status)->assertOk()->assertSee('7654321');
        $this->get($base.'?q=Unmatched&status='.$status)->assertOk()->assertSee('Keine Rechnungen gefunden.');
        $this->get($base.'?member='.$stranger->id)->assertOk()->assertDontSee('7654322');
        $this->get($base.'/'.$invoice)->assertOk()->assertSee('Visible position')->assertDontSee('Inactive position')->assertSee('90.00');
        $this->get($base.'/'.$hidden)->assertNotFound();
        $this->get('/portal/'.$this->other->slug.'/invoices/'.$invoice)->assertForbidden();
        $this->db->table('clubora.account_member_links')->where('account_id', $account->id)->update(['state' => 'revoked']);
        $this->get($base.'/'.$invoice)->assertForbidden();
    }

    private function sfvCompetitionFixture(): array
    {
        $fields = [];
        foreach (['H', 'K', 'T'] as $code) {
            $fields[$code] = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => 'field', 'code' => $code, 'name' => 'Field '.$code]);
        }
        $match = $this->db->table('clubora.sfv_schedule')->insertGetId(['tenant_id' => $this->own->id, 'match_id' => 987654, 'season_id' => 2027, 'title' => 'Fixture A - Fixture B', 'host_name' => 'Fixture A', 'guest_name' => 'Fixture B', 'starts_at' => now('Europe/Zurich')->addDay()->setTime(19, 30)->toIso8601String(), 'location' => 'Sportzentrum Tannenbrunn - 2, Sissach', 'state' => 'Angesetzt', 'home' => false]);

        return [$match, $fields, app(SfvCompetitionService::class)];
    }

    public function test_sfv_competition_preserves_local_data_and_is_idempotent(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $room = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => 'room', 'code' => 'R', 'name' => 'Room']);
        $side = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => 'side', 'code' => 'S', 'name' => 'Side']);
        $local = ['host_room_id' => $room, 'guest_room_id' => $room, 'host_side_id' => $side, 'guest_side_id' => $side, 'remarks' => 'Local note', 'host_category' => 'Ba', 'guest_category' => 'Guest category', 'host_club_number' => 3560, 'guest_club_number' => 42, 'show_on_signage' => false];
        $record = new Competition;
        $record->forceFill($local + ['tenant_id' => $this->own->id, 'plays_on' => now()->addDay()->toDateString(), 'host_name' => 'Old A', 'guest_name' => 'Old B', 'field_id' => $fields['H'], 'association_match_id' => 987654, 'active' => true])->save();
        $preview = $service->preview($this->actor, $this->own, $match);
        $this->assertSame($record->id, $service->apply($this->actor, $this->own, $preview));
        $record->refresh();
        $this->assertEquals($local, $record->only(array_keys($local)));
        $this->assertEquals($fields['K'], $record->field_id);
        $this->assertSame('19:30:00', $record->starts_at);
        $this->assertSame('Fixture A', $record->host_name);
        $revision = $record->revision;
        $service->apply($this->actor, $this->own, $service->preview($this->actor, $this->own, $match));
        $this->assertSame($revision, $record->fresh()->revision);
        $this->assertSame(1, Competition::where('tenant_id', $this->own->id)->count());
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'competitions')->count());
    }

    public function test_sfv_competition_manual_link_and_stale_preview(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $record = new Competition;
        $record->forceFill(['tenant_id' => $this->own->id, 'plays_on' => now('Europe/Zurich')->addDay()->toDateString(), 'host_name' => 'Manual', 'guest_name' => 'Guest', 'active' => true, 'remarks' => 'Keep'])->save();
        try {
            $service->preview($this->actor, $this->own, $match);
            $this->fail('Ambiguous import accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target', $e->errors());
        }
        $preview = $service->preview($this->actor, $this->own, $match, $record->id);
        $record->forceFill(['revision' => $record->fresh()->revision + 1])->save();
        try {
            $service->apply($this->actor, $this->own, $preview);
            $this->fail('Stale accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('match', $e->errors());
        }
        $service->apply($this->actor, $this->own, $service->preview($this->actor, $this->own, $match, $record->id));
        $this->assertEquals(987654, $record->fresh()->association_match_id);
        $this->assertSame('Keep', $record->fresh()->remarks);
        $record->forceFill(['active' => false])->save();
        $this->expectException(ValidationException::class);
        $service->preview($this->actor, $this->own, $match);
    }

    public function test_sfv_competition_venue_status_and_source_revision_guards(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $query = $this->db->table('clubora.sfv_schedule')->where('id', $match);
        foreach ([['location' => 'Basel'], ['location' => 'Sportzentrum Tannenbrunn - 2, Sissach', 'state' => 'Abgesagt'], ['state' => 'Angesetzt', 'host_name' => null], ['host_name' => 'A', 'starts_at' => now()->subDay()->toIso8601String()]] as $invalid) {
            $query->update($invalid);
            try {
                $service->preview($this->actor, $this->own, $match);
                $this->fail('Invalid source accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('match', $e->errors());
            }
        }
        $query->update(['starts_at' => now()->addDay()->toIso8601String()]);
        $preview = $service->preview($this->actor, $this->own, $match);
        $query->increment('revision');
        $this->expectException(ValidationException::class);
        $service->apply($this->actor, $this->own, $preview);
    }

    public function test_sfv_competition_web_preview_apply_and_role_boundary(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $this->get('/sfv-competitions')->assertOk()->assertSee('SFV abrufen');
        Livewire::test(SfvCompetitions::class)->set('match', (string) $match)->call('inspect')->assertHasNoErrors()->assertSee('Field K')->call('apply')->assertHasNoErrors();
        $this->assertSame(1, Competition::where('tenant_id', $this->own->id)->count());
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->own->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        $this->expectException(HttpException::class);
        $service->preview($this->actor, $this->own, $match);
    }

    public function test_sfv_competition_explicit_new_and_all_fields(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $manual = new Competition;
        $manual->forceFill(['tenant_id' => $this->own->id, 'plays_on' => now('Europe/Zurich')->addDay()->toDateString(), 'host_name' => 'Other clubs', 'guest_name' => 'Unrelated', 'active' => true])->save();
        $id = $service->apply($this->actor, $this->own, $service->preview($this->actor, $this->own, $match, null, true));
        $this->assertNotEquals($id, $manual->id);
        $this->assertNull($manual->fresh()->association_match_id);
        foreach (['1' => 'H', '2' => 'K', '3' => 'T'] as $number => $code) {
            $this->db->table('clubora.sfv_schedule')->where('id', $match)->update(['location' => 'Sportzentrum Tannenbrunn, Sissach - '.$number]);
            $service->apply($this->actor, $this->own, $service->preview($this->actor, $this->own, $match));
            $this->assertEquals($fields[$code], Competition::findOrFail($id)->field_id);
        }
        $this->assertSame(2, Competition::where('tenant_id', $this->own->id)->count());
    }

    public function test_sfv_competition_rejects_foreign_target(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $record = new Competition;
        $record->forceFill(['tenant_id' => $this->other->id, 'plays_on' => now()->addDay()->toDateString(), 'host_name' => 'Foreign', 'guest_name' => 'Other', 'active' => true])->save();
        $this->expectException(ModelNotFoundException::class);
        $service->preview($this->actor, $this->own, $match, $record->id);
    }

    public function test_sfv_competition_locked_preview_cannot_be_replaced(): void
    {
        [$match] = $this->sfvCompetitionFixture();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SfvCompetitions::class)->set('preview', ['match_id' => $match]);
    }

    private function accountAdminInput(): array
    {
        return ['username' => 'new-'.bin2hex(random_bytes(6)).'@example.test', 'display_name' => 'Fixture Account', 'password' => 'test-password-54321', 'password_confirmation' => 'test-password-54321', 'role' => 'member', 'active' => true, 'portal_enabled' => false, 'reason' => 'Isolated test change'];
    }

    public function test_account_administration_create_edit_filter_and_audit_no_secrets(): void
    {
        $service = app(AccountAdministrationService::class);
        $input = $this->accountAdminInput();
        Livewire::test(ManageAccounts::class)->callTableAction('newAccount', data: $input)->assertHasNoTableActionErrors();
        $account = Account::where('username', $input['username'])->sole();
        $link = TenantAccount::where('account_id', $account->id)->sole();
        $this->assertTrue(Hash::check($input['password'], $account->password));
        $edit = ['display_name' => 'Edited fixture', 'role' => 'trainer', 'active' => true, 'portal_enabled' => true, 'reason' => 'Reviewed trainer assignment', 'revision' => $link->revision, 'account_revision' => $account->revision];
        Livewire::test(ManageAccounts::class)->callTableAction('manage', $link, data: $edit)->assertHasNoTableActionErrors();
        $this->assertSame('trainer', $link->fresh()->role);
        $this->assertTrue($link->fresh()->portal_enabled);
        $audit = $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'tenant_accounts')->get()->toJson();
        $this->assertStringNotContainsString($input['password'], $audit);
        $this->assertStringNotContainsString($account->password, $audit);
        Livewire::test(ManageAccounts::class)->filterTable('role', 'trainer')->searchTable('Edited fixture')->assertCanSeeTableRecords([$link]);
        $this->get('/tenant-accounts')->assertOk();
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, $link->id, $edit);
    }

    public function test_account_administration_last_admin_and_role_revocation(): void
    {
        $service = app(AccountAdministrationService::class);
        $link = TenantAccount::where('tenant_id', $this->own->id)->where('account_id', $this->actor->id)->sole();
        $input = ['display_name' => $this->actor->display_name, 'role' => 'member', 'active' => true, 'portal_enabled' => false, 'reason' => 'Testing last admin guard', 'revision' => $link->revision, 'account_revision' => $this->actor->fresh()->revision];
        foreach ([['role' => 'member'], ['role' => 'admin', 'active' => false]] as $change) {
            try {
                $service->save($this->actor, $this->own, $link->id, array_replace($input, $change));
                $this->fail('Last admin removed');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('role', $e->errors());
            }
        }
        $new = $service->save($this->actor, $this->own, null, array_replace($this->accountAdminInput(), ['role' => 'admin']));
        $service->save($this->actor, $this->own, $link->id, $input);
        $this->assertFalse($service->allowed($this->actor, $this->own));
        $this->assertTrue($service->allowed($new->account, $this->own));
        $this->expectException(HttpException::class);
        $service->save($this->actor, $this->own, null, $this->accountAdminInput());
    }

    public function test_account_administration_denies_office_and_foreign_accounts(): void
    {
        $service = app(AccountAdministrationService::class);
        $foreign = TenantAccount::forceCreate(['tenant_id' => $this->other->id, 'account_id' => $this->actor->id, 'role' => 'member']);
        try {
            $service->save($this->actor, $this->own, $foreign->id, ['display_name' => 'X', 'role' => 'member', 'active' => true, 'portal_enabled' => false, 'reason' => 'Foreign account test', 'revision' => 1, 'account_revision' => 1]);
            $this->fail('Foreign account accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->own->id)->where('account_id', $this->actor->id)->update(['role' => 'office']);
        $this->get('/tenant-accounts')->assertForbidden();
        $this->expectException(HttpException::class);
        $service->save($this->actor, $this->own, null, $this->accountAdminInput());
    }

    public function test_account_administration_rejects_shared_identity_changes_and_unknown_input(): void
    {
        $service = app(AccountAdministrationService::class);
        foreach ([['role' => 'superadmin'], ['tenant_id' => $this->other->id], ['password_confirmation' => 'wrong']] as $change) {
            try {
                $service->save($this->actor, $this->own, null, array_replace($this->accountAdminInput(), $change));
                $this->fail('Invalid accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $link = $service->save($this->actor, $this->own, null, $this->accountAdminInput());
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->other->id, 'account_id' => $link->account_id, 'role' => 'member']);
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, $link->id, ['display_name' => 'Changed global name', 'role' => 'member', 'active' => true, 'portal_enabled' => false, 'reason' => 'Shared identity test', 'revision' => $link->revision, 'account_revision' => $link->account->revision]);
    }

    public function test_sfv_batch_preserves_manual_conflicts_and_rolls_back_stale_batch(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $source = (array) $this->db->table('clubora.sfv_schedule')->where('id', $match)->first();
        unset($source['id']);
        $source['match_id']++;
        $second = $this->db->table('clubora.sfv_schedule')->insertGetId($source);
        $source['match_id']++;
        $source['location'] = 'Away field';
        $this->db->table('clubora.sfv_schedule')->insert($source);
        $date = now('Europe/Zurich')->addDay()->toDateString();
        $preview = $service->previewBatch($this->actor, $this->own, $date, $date);
        $this->assertCount(2, $preview['entries']);
        $this->assertCount(1, $preview['issues']);
        $this->db->table('clubora.sfv_schedule')->where('id', $second)->increment('revision');
        try {
            $service->applyBatch($this->actor, $this->own, $preview['entries']);
            $this->fail('Stale batch accepted');
        } catch (ValidationException) {
            $this->assertSame(0, Competition::where('tenant_id', $this->own->id)->count());
        }
        $fresh = $service->previewBatch($this->actor, $this->own, $date, $date);
        $this->assertSame(2, $service->applyBatch($this->actor, $this->own, $fresh['entries']));
        $repeat = $service->previewBatch($this->actor, $this->own, $date, $date);
        $this->assertSame(2, $repeat['unchanged']);
        $this->assertCount(0, $repeat['entries']);
        Livewire::test(SfvCompetitions::class)->set('from', $date)->set('to', $date)->call('inspectBatch')->assertHasNoErrors()->assertSee('Sammelübernahme')->assertSee('unverändert');
    }

    public function test_sfv_batch_limit_and_locked_preview(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $source = (array) $this->db->table('clubora.sfv_schedule')->where('id', $match)->first();
        unset($source['id']);
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $source['match_id']++;
            $rows[] = $source;
        }
        $this->db->table('clubora.sfv_schedule')->insert($rows);
        $date = now('Europe/Zurich')->addDay()->toDateString();
        try {
            $service->previewBatch($this->actor, $this->own, $date, $date);
            $this->fail('Unlimited batch');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('to', $error->errors());
        }
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SfvCompetitions::class)->set('batchPreview', ['entries' => []]);
    }

    public function test_sfv_withdrawal_hides_cancelled_and_moved_games_without_losing_rooms(): void
    {
        [$match,$fields,$service] = $this->sfvCompetitionFixture();
        $id = $service->apply($this->actor, $this->own, $service->preview($this->actor, $this->own, $match));
        $room = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => 'room', 'code' => 'R', 'name' => 'Room']);
        Competition::whereKey($id)->update(['host_room_id' => $room, 'remarks' => 'Keep']);
        $query = $this->db->table('clubora.sfv_schedule')->where('id', $match);
        $query->update(['state' => 'Abgesagt']);
        $preview = $service->review($this->actor, $this->own, $match);
        $this->assertSame('withdrawal', $preview['kind']);
        $service->apply($this->actor, $this->own, $preview);
        $record = Competition::findOrFail($id);
        $this->assertFalse($record->show_on_signage);
        $this->assertTrue($record->active);
        $this->assertEquals($room, $record->host_room_id);
        $this->assertSame('Keep', $record->remarks);
        $query->update(['state' => 'neu angesetzt']);
        $service->apply($this->actor, $this->own, $service->review($this->actor, $this->own, $match));
        $this->assertFalse($record->fresh()->show_on_signage);
        $record->forceFill(['show_on_signage' => true, 'revision' => $record->revision + 1])->save();
        $query->update(['location' => 'Basel']);
        $preview = $service->review($this->actor, $this->own, $match);
        $service->apply($this->actor, $this->own, $preview);
        $this->assertFalse($record->fresh()->show_on_signage);
        $this->assertSame(2, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->whereRaw("after_value->>'action' = 'sfv_withdrawal'")->count());
    }

    public function test_account_administration_password_reset_and_duplicate_username(): void
    {
        $service = app(AccountAdministrationService::class);
        $data = $this->accountAdminInput();
        $link = $service->save($this->actor, $this->own, null, $data);
        try {
            $service->save($this->actor, $this->own, null, array_replace($data, ['username' => strtoupper($data['username'])]));
            $this->fail('Duplicate accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('username', $error->errors());
        }
        $account = $link->account;
        $password = 'replacement-password-67890';
        $service->save($this->actor, $this->own, $link->id, ['display_name' => $account->display_name, 'role' => 'member', 'active' => false, 'portal_enabled' => false, 'reason' => 'Password change fixture', 'revision' => $link->revision, 'account_revision' => $account->revision, 'password' => $password, 'password_confirmation' => $password]);
        $this->assertTrue(Hash::check($password, $account->fresh()->password));
        $this->assertFalse($account->fresh()->active);
        $audit = $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'tenant_accounts')->get()->toJson();
        $this->assertStringNotContainsString($password, $audit);
    }

    public function test_football_club_forms_revision_audit_and_immutable_number(): void
    {
        $this->get('/football-clubs')->assertOk();
        Livewire::test(ManageClubs::class)->callTableAction('create', data: ['club_number' => 3560, 'name' => 'Fixture Sissach', 'active' => true, 'logo_enabled' => true])->assertHasNoTableActionErrors();
        $club = FootballClub::where('tenant_id', $this->own->id)->sole();
        $this->assertStringEndsWith('/3560.gif', $club->logo_url);
        Livewire::test(ManageClubs::class)->callTableAction('edit', $club, data: ['name' => 'Changed fixture', 'logo_enabled' => false])->assertHasNoTableActionErrors();
        $this->assertNull($club->fresh()->logo_url);
        $this->assertEquals(2, $club->fresh()->revision);
        $this->assertEquals(2, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'football_clubs')->count());
        $base = ['revision' => 2, 'name' => 'Changed fixture', 'active' => true, 'logo_enabled' => false];
        foreach ([['revision' => 1], ['club_number' => 123], ['logo_url' => 'https://untrusted.invalid/logo'], ['tenant_id' => $this->other->id]] as $bad) {
            try {
                app(FacilityService::class)->save($this->actor, $this->own, FootballClub::class, $club->id, array_replace($base, $bad));
                $this->fail('Unsafe club change accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertEquals(2, $club->fresh()->revision);
        $this->expectException(ValidationException::class);
        app(FacilityService::class)->save($this->actor, $this->own, FootballClub::class, null, ['club_number' => 3560, 'name' => 'Duplicate', 'active' => true, 'logo_enabled' => true]);
    }

    public function test_football_club_lookup_permissions_and_filter_search(): void
    {
        $service = app(FacilityService::class);
        $club = $service->save($this->actor, $this->own, FootballClub::class, null, ['club_number' => 3560, 'name' => 'Sissach fixture', 'active' => true, 'logo_enabled' => true]);
        $service->save($this->actor, $this->own, FootballClub::class, null, ['club_number' => 42, 'name' => 'Inactive fixture', 'active' => false, 'logo_enabled' => true]);
        $foreignId = $this->db->table('clubora.football_clubs')->insertGetId(['tenant_id' => $this->other->id, 'club_number' => 991, 'name' => 'Foreign fixture']);
        $this->assertSame([3560 => 'Sissach fixture (3560)'], FootballClubResource::searchClubs('fixture'));
        $this->assertSame([3560 => 'Sissach fixture (3560)'], FootballClubResource::searchClubs('356'));
        $this->assertStringContainsString('Altbestand', FootballClubResource::clubLabel(991));
        $this->assertStringNotContainsString('Foreign', FootballClubResource::clubLabel(991));
        $page = Livewire::test(ManageClubs::class)->filterTable('active', true)->searchTable('fixture')->assertCountTableRecords(1)->assertCanSeeTableRecords([$club]);
        $page->callAction('saveView', data: ['name' => 'Clubs fixture', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ManageClubs::class)->assertCountTableRecords(1)->assertSet('tableSearch', 'fixture');
        try {
            $service->save($this->actor, $this->own, FootballClub::class, $foreignId, ['revision' => 1, 'name' => 'Denied', 'active' => true, 'logo_enabled' => true]);
            $this->fail('Foreign club edited');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
        foreach (['trainer', 'member', 'technical_lead'] as $role) {
            [$actor] = $this->portalActor($role);
            try {
                $service->save($actor, $this->own, FootballClub::class, null, ['club_number' => 1, 'name' => 'Denied', 'active' => true, 'logo_enabled' => true]);
                $this->fail('Role wrote club');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_football_club_selection_preserves_team_names_and_historical_numbers(): void
    {
        $ids = $this->facilityFixture();
        $service = app(FacilityService::class);
        $club = $service->save($this->actor, $this->own, FootballClub::class, null, ['club_number' => 3560, 'name' => 'SV Sissach', 'active' => true, 'logo_enabled' => true]);
        $id = $this->db->table('clubora.competitions')->insertGetId(['tenant_id' => $this->own->id, 'plays_on' => now('Europe/Zurich')->toDateString(), 'host_name' => 'Sissach Ba', 'guest_name' => 'Guest B', 'guest_club_number' => 9876, 'host_room_id' => $ids['room']['1'], 'association_match_id' => 999, 'source' => 'SFV']);
        $game = Competition::findOrFail($id);
        Livewire::test(ManageCompetitions::class)->callTableAction('edit', $game, data: ['host_club_number' => 3560])->assertHasNoTableActionErrors();
        $game->refresh();
        $this->assertEquals(3560, $game->host_club_number);
        $this->assertEquals(9876, $game->guest_club_number);
        $this->assertSame('Sissach Ba', $game->host_name);
        $this->assertEquals($ids['room']['1'], $game->host_room_id);
        $this->assertEquals(999, $game->association_match_id);
        $service->save($this->actor, $this->own, FootballClub::class, $club->id, ['revision' => 1, 'name' => 'SV Sissach', 'active' => false, 'logo_enabled' => true]);
        Livewire::test(ManageCompetitions::class)->callTableAction('edit', $game, data: ['remarks' => 'Room note'])->assertHasNoTableActionErrors();
        $this->assertEquals(3560, $game->fresh()->host_club_number);
        $base = ['plays_on' => now('Europe/Zurich')->toDateString(), 'host_name' => 'New', 'guest_name' => 'Guest', 'active' => true, 'show_on_signage' => true];
        $this->db->table('clubora.football_clubs')->insert(['tenant_id' => $this->other->id, 'club_number' => 9999, 'name' => 'Foreign']);
        foreach ([3560, 9876, 9999, 2147483648] as $number) {
            try {
                $service->save($this->actor, $this->own, Competition::class, null, $base + ['host_club_number' => $number]);
                $this->fail('Invalid or inactive club selected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('host_club_number', $e->errors());
            }
        }
    }

    public function test_football_club_signage_logo_scope_and_disabled_fallback(): void
    {
        $this->db->table('clubora.football_clubs')->insert([
            ['tenant_id' => $this->own->id, 'club_number' => 3560, 'name' => 'Own club', 'logo_enabled' => true],
            ['tenant_id' => $this->own->id, 'club_number' => 42, 'name' => 'Disabled logo', 'logo_enabled' => false],
            ['tenant_id' => $this->other->id, 'club_number' => 99, 'name' => 'Foreign logo', 'logo_enabled' => true],
        ]);
        foreach ([[3560, 42], [99, null]] as [$host, $guest]) {
            $this->db->table('clubora.competitions')->insert(['tenant_id' => $this->own->id, 'plays_on' => now('Europe/Zurich')->toDateString(), 'host_name' => 'Team host', 'guest_name' => 'Team guest', 'host_club_number' => $host, 'guest_club_number' => $guest]);
        }
        Livewire::test(SignagePreview::class)->assertSeeHtml('/logos/Verein/3560.gif')->assertDontSeeHtml('/logos/Verein/42.gif')->assertDontSeeHtml('/logos/Verein/99.gif')->assertSeeHtml('referrerpolicy="no-referrer"')->assertSeeHtml('x-on:error="failed = true"');
        $this->db->table('clubora.football_clubs')->where('tenant_id', $this->own->id)->update(['active' => false]);
        Livewire::test(SignagePreview::class)->assertDontSeeHtml('/logos/Verein/3560.gif')->assertSee('Team host');
    }

    public function test_football_club_import_collapses_duplicates_but_not_conflicting_names(): void
    {
        $rows = collect([
            [1, 'Club A ', null], [1, 'Club A', null], [2, 'Old name', '2020-01-01'], [2, 'New name', null],
            [3, 'Conflict A', null], [3, 'Conflict B', null], [0, 'Invalid', null], [4, '', null],
            [5, 'Inactive', '2020-01-01'], [null, 'Invalid', null],
        ])->map(fn ($r) => (object) ['VereinID' => $r[0], 'Vereinsname' => $r[1], 'sdeletedAt' => $r[2]]);
        $result = app(FootballClubImporter::class)->prepare($rows);
        $this->assertSame([3], $result['conflicts']);
        $this->assertSame(3, $result['invalid_rows']);
        $this->assertSame([
            ['club_number' => 1, 'name' => 'Club A', 'active' => true],
            ['club_number' => 2, 'name' => 'New name', 'active' => true],
            ['club_number' => 5, 'name' => 'Inactive', 'active' => false],
        ], $result['clubs']);
        $this->assertSame(['already_imported' => true], app(FootballClubImporter::class)->run());
    }

    public function test_football_club_missing_mapping_filter_remains_tenant_scoped_and_saved(): void
    {
        $this->db->table('clubora.football_clubs')->insert([
            ['tenant_id' => $this->own->id, 'club_number' => 1, 'name' => 'Active', 'active' => true],
            ['tenant_id' => $this->own->id, 'club_number' => 2, 'name' => 'Inactive', 'active' => false],
            ['tenant_id' => $this->other->id, 'club_number' => 3, 'name' => 'Foreign', 'active' => true],
        ]);
        foreach ([1, 2, 3, null] as $number) {
            $this->db->table('clubora.competitions')->insert(['tenant_id' => $this->own->id, 'plays_on' => now('Europe/Zurich')->toDateString(), 'host_name' => 'Mapping fixture', 'guest_name' => 'Guest', 'host_club_number' => 1, 'guest_club_number' => $number]);
        }
        $page = Livewire::test(ManageCompetitions::class)->filterTable('missing_club')->searchTable('Mapping fixture')->assertCountTableRecords(3);
        $page->callAction('saveView', data: ['name' => 'Missing clubs', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ManageCompetitions::class)->assertCountTableRecords(3)->assertSet('tableSearch', 'Mapping fixture');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to rolled-back ClubUnio integration tests.');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement('SET LOCAL search_path TO clubora');
        $suffix = bin2hex(random_bytes(6));
        $this->own = Tenant::create(['slug' => 'test-'.$suffix, 'name' => 'Test club']);
        $this->other = Tenant::create(['slug' => 'other-'.$suffix, 'name' => 'Other club']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'test-'.$suffix, 'display_name' => 'Test Admin',
            'password' => password_hash('fixture-password', PASSWORD_BCRYPT), 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->own->id, 'account_id' => $this->actor->id, 'role' => 'admin']);
        foreach ([$this->own, $this->other] as $tenant) {
            $this->db->table('members')->insert(['tenant_id' => $tenant->id, 'legacy_id' => 999999,
                'first_name' => $tenant->id === $this->own->id ? 'OWN-MEMBER' : 'FOREIGN-MEMBER', 'last_name' => 'Fixture']);
            $this->db->table('tenant_settings')->insert(['tenant_id' => $tenant->id, 'area' => 'general',
                'key' => 'fixture', 'label' => 'Fixture setting', 'value_type' => 'string', 'is_secret' => true,
                'encrypted_value' => Crypt::encryptString('secret-fixture')]);
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->own);
    }

    public function test_contributions_catalog_forms_and_rule_overlap_protection(): void
    {
        $this->get('/contribution-prices')->assertOk();
        Livewire::test(ManagePrices::class)
            ->callTableAction('create', data: ['name' => 'Fixture tariff', 'amount' => '250.00', 'active' => true])->assertHasNoTableActionErrors();
        $price = ContributionPrice::where('tenant_id', $this->own->id)->sole();
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture season']);
        $gender = $this->db->table('clubora.genders')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'M', 'name' => 'Male fixture']);
        $service = app(ContributionTrainingService::class);
        $input = ['season_id' => $season, 'gender_id' => $gender, 'price_id' => $price->id, 'year_from' => 2010, 'year_to' => 2015, 'active' => true];
        $rule = $service->save($this->actor, $this->own, ContributionRule::class, null, $input);
        $this->get('/contribution-rules')->assertOk()->assertSee('Fixture tariff');
        try {
            $service->save($this->actor, $this->own, ContributionRule::class, null, array_replace($input, ['year_from' => 2015, 'year_to' => 2019]));
            $this->fail('Overlapping rule accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('year_from', $error->errors());
        }
        $service->save($this->actor, $this->own, ContributionRule::class, $rule->id, $input + ['revision' => 1]);
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, ContributionRule::class, $rule->id, $input + ['revision' => 1]);
    }

    public function test_training_slot_form_lookups_times_audit_and_tenant_boundary(): void
    {
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture season']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture training']);
        $ts = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Fixture training / season']);
        $ids = [];
        foreach (['period', 'weekday', 'field'] as $category) {
            $ids[$category.'_id'] = $this->db->table('clubora.training_options')->insertGetId(['tenant_id' => $this->own->id, 'category' => $category, 'code' => 'fixture', 'name' => $category]);
        }
        $input = $ids + ['team_season_id' => $ts, 'starts_at' => '18:00', 'ends_at' => '19:30', 'all_fields' => true, 'without_main' => false, 'synthetic_only' => false, 'website_visible' => true, 'active' => true];
        $this->get('/training-slots')->assertOk();
        Livewire::test(ManageSlots::class)->callTableAction('create', data: $input)->assertHasNoTableActionErrors();
        $slot = TrainingSlot::where('tenant_id', $this->own->id)->sole();
        $this->assertSame('18:00:00', $slot->starts_at);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'training_slots')->count());
        $service = app(ContributionTrainingService::class);
        foreach ([['ends_at' => '17:00'], ['field_id' => $ids['period_id']]] as $bad) {
            try {
                $service->save($this->actor, $this->own, TrainingSlot::class, $slot->id, array_replace($input, ['revision' => 1], $bad));
                $this->fail('Invalid training accepted');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $this->get('/clubunio/'.$this->other->slug.'/training-slots')->assertNotFound();
        [$trainer] = $this->portalActor('trainer');
        $this->expectException(HttpException::class);
        $service->save($trainer, $this->own, TrainingSlot::class, null, $input);
    }

    public function test_dashboard_widget_and_training_option_form(): void
    {
        $this->get('/')->assertOk();
        Livewire::test(ClubOverview::class)->assertSee('Aktive Mitglieder')->assertSee('Spesen zur Prüfung');
        $this->get('/training-options')->assertOk();
        Livewire::test(ManageOptions::class)->callTableAction('create', data: ['category' => 'field', 'code' => 'extra', 'name' => 'Zusatzplatz', 'sort_order' => 10, 'active' => true])->assertHasNoTableActionErrors();
        $option = TrainingOption::where('tenant_id', $this->own->id)->sole();
        Livewire::test(ManageOptions::class)->callTableAction('edit', $option, data: ['revision' => 1, 'name' => 'Zusatzplatz neu', 'sort_order' => 10, 'active' => true])->assertHasNoTableActionErrors();
        $this->assertSame('extra', $option->fresh()->code);
    }

    public function test_training_legacy_time_normalization_rejects_invalid_values(): void
    {
        $importer = app(ContributionTrainingImporter::class);
        $this->assertSame('19:45:00', $importer->time('19.45'));
        $this->assertSame('08:05:00', $importer->time('8:05'));
        $this->expectException(\LogicException::class);
        $importer->time('25:61');
    }

    public function test_member_reports_and_status_tabs_keep_scope_and_search(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->sole();
        $member->forceFill(['honorary' => true])->save();
        $this->get('/member-reports')->assertOk();
        Livewire::test(ListMemberReports::class)
            ->set('activeTab', 'honorary')->searchTable('OWN-MEMBER')->assertSee('OWN-MEMBER')->assertDontSee('FOREIGN-MEMBER')
            ->set('activeTab', 'sponsor')->assertCanNotSeeTableRecords([$member]);
        Livewire::test(ListMemberReports::class)->set('activeTab', 'sponsor')->searchTable('OWN-MEMBER')
            ->callTableAction('export')->assertHasNoActionErrors()
            ->assertFileDownloaded('mitgliederliste-'.now('Europe/Zurich')->format('Y-m-d').'.csv');
        $export = $this->db->table('object_changes')->where('tenant_id', $this->own->id)
            ->where('object_type', 'report_exports')->orderByDesc('id')->value('after_value');
        $this->assertSame(0, json_decode($export, true)['row_count']);
        [$registration, $statuses] = $this->reviewFixture();
        Livewire::test(ListRegistrations::class)->assertSee('submitted (1)')
            ->set('activeTab', 'status_'.$statuses['submitted'])->searchTable('Fixture')->assertSee('Registration Fixture')
            ->set('activeTab', 'status_'.$statuses['review'])->assertDontSee('Registration Fixture');
        $this->get('/clubunio/'.$this->other->slug.'/member-reports')->assertNotFound();
    }

    public function test_status_appearance_and_charts_share_colors_and_tenant_scope(): void
    {
        $appearance = StatusAppearance::class;
        $this->assertSame($appearance::hex('Offen'), $appearance::hex(' offen '));
        $this->assertNotSame($appearance::hex('Offen'), $appearance::hex('Bezahlt'));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $appearance::hex('Future custom value'));
        $status = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture', 'name' => 'Fixture status']);
        Member::where('tenant_id', $this->own->id)->update(['status_id' => $status]);
        $chart = Livewire::test(MembersByStatus::class);
        $data = (new \ReflectionMethod($chart->instance(), 'getData'))->invoke($chart->instance());
        $this->assertSame(['Fixture status'], $data['labels']);
        $this->assertSame([1], $data['datasets'][0]['data']);
        $this->assertSame([$appearance::hex('Fixture status')], $data['datasets'][0]['backgroundColor']);
        Livewire::test(ExpensesByStatus::class)->assertOk();
    }

    public function test_saved_views_roundtrip_default_and_url_precedence(): void
    {
        $page = Livewire::test(ListMembers::class)->searchTable('OWN-MEMBER')->sortTable('last_name', 'desc');
        $page->callAction('saveView', data: ['name' => 'Meine Mitglieder', 'is_default' => true])->assertHasNoActionErrors();
        $view = SavedView::where('tenant_id', $this->own->id)->sole();
        $this->assertSame('OWN-MEMBER', $view->state_encrypted['search']);
        $this->assertStringNotContainsString('OWN-MEMBER', $view->getRawOriginal('state_encrypted'));
        $this->assertArrayNotHasKey('state_encrypted', $view->toArray());
        Livewire::test(ListMembers::class)->assertSet('tableSearch', 'OWN-MEMBER')->assertSet('tableSort', 'last_name:desc');
        Livewire::withQueryParams(['search' => 'EXPLICIT'])->test(ListMembers::class)->assertSet('tableSearch', 'EXPLICIT');
        $page->searchTable('Changed')->callAction('loadView', data: ['view_id' => $view->id])->assertSet('tableSearch', 'OWN-MEMBER');
        $page->callAction('manageView', data: ['view_id' => $view->id, 'name' => 'Umbenannt', 'is_default' => false, 'replace_state' => false, 'revision' => $view->revision])->assertHasNoActionErrors();
        $this->assertSame('Umbenannt', $view->fresh()->name);
        $this->assertFalse($view->fresh()->is_default);
        $page->callAction('deleteView', data: ['view_id' => $view->id, 'revision' => $view->fresh()->revision])->assertHasNoActionErrors();
        $this->assertNull($view->fresh());
    }

    public function test_saved_views_scope_revision_and_single_default(): void
    {
        $service = app(SavedViewService::class);
        $state = ['version' => 1, 'filters' => [], 'search' => '', 'sort' => null, 'tab' => null, 'columns' => []];
        $one = $service->save($this->actor, $this->own, 'members', null, ['name' => 'One', 'is_default' => true], $state);
        $two = $service->save($this->actor, $this->own, 'members', null, ['name' => 'Two', 'is_default' => true], $state);
        $this->assertFalse($one->fresh()->is_default);
        $this->assertSame(1, $service->query($this->actor, $this->own, 'members')->where('is_default', true)->count());
        $this->assertSame(0, $service->query($this->actor, $this->own, 'invoices')->count());
        [$otherActor] = $this->portalActor('office');
        $this->assertSame(0, $service->query($otherActor, $this->own, 'members')->count());
        try {
            $service->save($otherActor, $this->own, 'members', $two->id, ['name' => 'Stolen', 'is_default' => false, 'revision' => 1], $state);
            $this->fail('Foreign view accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, 'members', $one->id, ['name' => 'Stale', 'is_default' => false, 'revision' => 1], $state);
    }

    public function test_saved_view_rejects_stale_lookup_and_keeps_current_selection(): void
    {
        $status = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'saved', 'name' => 'Saved status']);
        $page = Livewire::test(ListMembers::class)->filterTable('status_id', $status);
        $page->callAction('saveView', data: ['name' => 'Statusansicht', 'is_default' => false])->assertHasNoActionErrors();
        $view = SavedView::where('tenant_id', $this->own->id)->sole();
        $this->db->table('clubora.member_statuses')->where('id', $status)->delete();
        $page->searchTable('Retain');
        $page->callAction('loadView', data: ['view_id' => $view->id])->assertHasActionErrors()->assertSet('tableSearch', 'Retain');
    }

    public function test_saved_view_tabs_columns_and_session_isolation(): void
    {
        $page = Livewire::test(ListMemberReports::class)->set('activeTab', 'honorary');
        $page->callAction('saveView', data: ['name' => 'Ehrenmitglieder', 'is_default' => true])->assertHasNoActionErrors();
        Livewire::test(ListMemberReports::class)->assertSet('activeTab', 'honorary');
        $key = $page->instance()->getTableFiltersSessionKey();
        [$otherActor] = $this->portalActor('office');
        $this->actingAs($otherActor, 'clubunio');
        $otherPage = Livewire::test(ListMemberReports::class)->assertSet('activeTab', 'all');
        $this->assertNotSame($key, $otherPage->instance()->getTableFiltersSessionKey());
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_tenant_pages_hide_foreign_members_and_reject_foreign_routes(): void
    {
        $this->get('/members')->assertOk()->assertSee('OWN-MEMBER')->assertDontSee('FOREIGN-MEMBER');
        $this->get('/clubunio/'.$this->other->slug.'/members')->assertNotFound();
    }

    private function portalActor(string $role = 'member', bool $newMember = false): array
    {
        $account = new Account;
        $account->forceFill(['username' => 'portal-'.bin2hex(random_bytes(5)).'@example.invalid', 'display_name' => 'Portal Fixture',
            'password' => password_hash('fixture-password', PASSWORD_BCRYPT), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->own->id, 'account_id' => $account->id, 'role' => $role, 'portal_enabled' => true]);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        if ($newMember) {
            $member = new Member;
            $member->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Separate', 'last_name' => 'Fixture'])->save();
        }
        $this->db->table('clubora.account_member_links')->insert(['tenant_id' => $this->own->id, 'account_id' => $account->id,
            'member_id' => $member->id, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);

        return [$account, $member];
    }

    public function test_link_review_filament_action_and_role_denial(): void
    {
        [$account, $member] = $this->portalActor();
        $link = AccountMemberLink::where('tenant_id', $this->own->id)->where('account_id', $account->id)->firstOrFail();
        Livewire::test(ListAccountMemberLinks::class)
            ->mountTableAction('review', $link)
            ->setTableActionData(['account_id' => $account->id, 'member_id' => $member->id, 'kind' => 'self', 'state' => 'revoked',
                'revision' => 1, 'reason' => 'Synthetic identity review'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('revoked', $link->fresh()->state);
        $this->actingAs($account, 'clubunio')->get('/account-member-links')->assertForbidden();
    }

    public function test_portal_status_and_account_revocation_and_filters(): void
    {
        [$account, $member] = $this->portalActor();
        $status = $this->db->table('clubora.member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'test', 'name' => 'Fixture status']);
        $member->forceFill(['status_id' => $status, 'mother_name' => 'PRIVATE-MOTHER', 'bexio_contact_id' => 987654321])->save();
        $url = '/portal/'.$this->own->slug.'/members?status='.$status.'&q=OWN';
        $this->actingAs($account, 'portal')->get($url)->assertOk()->assertSee('OWN-MEMBER')->assertDontSee('PRIVATE-MOTHER')->assertDontSee('987654321');
        $access = app(MemberAccessService::class);
        $this->db->table('clubora.member_statuses')->where('id', $status)->update(['ends_membership' => true]);
        $this->assertFalse($access->canEnter($account, $this->own));
        $this->get($url)->assertForbidden();
        $this->db->table('clubora.member_statuses')->where('id', $status)->update(['ends_membership' => false]);
        $this->db->table('clubora.tenant_accounts')->where('account_id', $account->id)->update(['portal_enabled' => false]);
        $this->assertFalse($access->canEnter($account, $this->own));
        $account->forceFill(['active' => false])->save();
        $this->assertFalse($access->canEnter($account, $this->own));
    }

    public function test_portal_own_identity_and_admin_boundary(): void
    {
        [$account, $member] = $this->portalActor();
        $access = app(MemberAccessService::class);
        $this->assertTrue($access->canEnter($account, $this->own));
        $this->assertFalse($account->canAccessTenant($this->own));
        $this->assertFalse($access->canEnter($account, $this->other));
        $this->assertSame([$member->id], $access->visibleMembers($account, $this->own)->pluck('id')->all());
        $this->actingAs($account, 'portal')->get('/portal/'.$this->own->slug.'/members?q=OWN')->assertOk()->assertSee('OWN-MEMBER')->assertDontSee('FOREIGN-MEMBER');
        $this->get('/portal/'.$this->other->slug.'/members')->assertForbidden();
        $this->expectException(HttpException::class);
        app(AccountLinkService::class)->save($account, $this->own, null, []);
    }

    public function test_parent_approval_age_exit_and_revocation_are_checked_each_request(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Europe/Zurich'));
        [$account, $ownMember] = $this->portalActor();
        $ownMember->forceFill(['exit_date' => '2026-10-11'])->save();
        $child = new Member;
        $child->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Child Fixture', 'birth_date' => '2012-02-29'])->save();
        $id = $this->db->table('clubora.account_member_links')->insertGetId(['tenant_id' => $this->own->id, 'account_id' => $account->id,
            'member_id' => $child->id, 'kind' => 'parent', 'state' => 'pending', 'source' => 'test']);
        $childInvoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $child->id]);
        $invoiceAccess = app(InvoiceAccessService::class);
        $this->assertFalse($invoiceAccess->visible($account, $this->own)->whereKey($childInvoice)->exists());
        $access = app(MemberAccessService::class);
        $this->assertFalse($access->canEnter($account, $this->own));
        $service = app(AccountLinkService::class);
        $data = ['account_id' => $account->id, 'member_id' => $child->id, 'kind' => 'parent', 'state' => 'approved', 'revision' => 1, 'reason' => 'Synthetic proof checked'];
        $service->save($this->actor, $this->own, $id, $data);
        $this->assertTrue($access->canEnter($account, $this->own));
        $this->assertSame([$child->id], $access->represented($account, $this->own)->pluck('id')->all());
        $this->assertTrue($invoiceAccess->visible($account, $this->own)->whereKey($childInvoice)->exists());
        $child->forceFill(['birth_date' => '2008-10-11'])->save();
        $this->assertFalse($access->canEnter($account, $this->own));
        $this->assertFalse($invoiceAccess->visible($account, $this->own)->whereKey($childInvoice)->exists());
        try {
            $service->save($this->actor, $this->own, $id, array_replace($data, ['revision' => 2]));
            $this->fail('Adult parent approval accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('member_id', $error->errors());
        }
        $child->forceFill(['birth_date' => '2012-02-29'])->save();
        $service->save($this->actor, $this->own, $id, array_replace($data, ['state' => 'revoked', 'revision' => 2]));
        $this->assertFalse($access->canEnter($account, $this->own));
        $this->assertSame(2, $this->db->table('clubora.object_changes')->where('object_type', 'account_member_links')->where('object_id', $id)->count());
    }

    public function test_trainer_and_tl_only_see_active_assigned_team_members(): void
    {
        [$account, $ownMember] = $this->portalActor('trainer');
        $season = $this->db->table('clubora.seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test season']);
        $team = $this->db->table('clubora.teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test team']);
        $teamSeason = $this->db->table('clubora.team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Test team / season']);
        $player = new Member;
        $player->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Team Player'])->save();
        $unrelated = new Member;
        $unrelated->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Unrelated'])->save();
        $this->db->table('clubora.team_staff')->insert(['tenant_id' => $this->own->id, 'member_id' => $ownMember->id, 'team_season_id' => $teamSeason]);
        $this->db->table('clubora.team_players')->insert(['tenant_id' => $this->own->id, 'member_id' => $player->id, 'team_season_id' => $teamSeason]);
        $playerInvoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $player->id]);
        $foreignInvoice = $this->db->table('clubora.invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $unrelated->id]);
        $invoiceAccess = app(InvoiceAccessService::class);
        $this->assertSame([$playerInvoice], $invoiceAccess->visible($account, $this->own)->pluck('id')->all());
        $access = app(MemberAccessService::class);
        $this->assertEqualsCanonicalizing([$ownMember->id, $player->id], $access->visibleMembers($account, $this->own)->pluck('id')->all());
        $this->db->table('clubora.tenant_accounts')->where('account_id', $account->id)->update(['role' => 'technical_lead']);
        $this->assertSame([$ownMember->id], $access->visibleMembers($account, $this->own)->pluck('id')->all());
        $this->assertFalse($invoiceAccess->visible($account, $this->own)->whereKey($playerInvoice)->exists());
        $this->db->table('clubora.technical_leads')->insert(['tenant_id' => $this->own->id, 'member_id' => $ownMember->id, 'team_season_id' => $teamSeason]);
        $this->assertEqualsCanonicalizing([$ownMember->id, $player->id], $access->visibleMembers($account, $this->own)->pluck('id')->all());
        $this->assertTrue($invoiceAccess->visible($account, $this->own)->whereKey($playerInvoice)->exists());
        $this->assertFalse($invoiceAccess->visible($account, $this->own)->whereKey($foreignInvoice)->exists());
        $player->forceFill(['exit_date' => now('Europe/Zurich')->toDateString()])->save();
        $this->assertSame([$ownMember->id], $access->visibleMembers($account, $this->own)->pluck('id')->all());
        $this->db->table('clubora.technical_leads')->where('tenant_id', $this->own->id)->update(['active' => false]);
        $this->assertSame([], $access->responsibleTeams($account, $this->own));
        $this->assertFalse($invoiceAccess->visible($account, $this->own)->whereKey($playerInvoice)->exists());
    }

    public function test_portal_login_and_session_fail_closed_after_exit(): void
    {
        [$account, $member] = $this->portalActor();
        $this->post('/portal/login', ['username' => strtoupper($account->username), 'password' => 'fixture-password'])
            ->assertRedirect('/portal/'.$this->own->slug.'/members');
        $this->assertAuthenticatedAs($account, 'portal');
        $member->forceFill(['exit_date' => now('Europe/Zurich')->toDateString()])->save();
        $this->get('/portal/'.$this->own->slug.'/members')->assertForbidden();
        $this->post('/portal/login', ['username' => $account->username, 'password' => 'fixture-password'])->assertSessionHasErrors('username');
        $this->assertGuest('portal');
    }

    public function test_link_review_rejects_foreign_member_and_stale_version(): void
    {
        [$account, $member] = $this->portalActor();
        $service = app(AccountLinkService::class);
        $foreign = Member::withoutGlobalScopes()->where('tenant_id', $this->other->id)->firstOrFail();
        try {
            $service->save($this->actor, $this->own, null, ['account_id' => $account->id, 'member_id' => $foreign->id, 'kind' => 'parent', 'state' => 'pending', 'reason' => 'Invalid foreign assignment']);
            $this->fail('Foreign member accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('member_id', $error->errors());
        }
        $id = $this->db->table('clubora.account_member_links')->where('account_id', $account->id)->value('id');
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, $id, ['account_id' => $account->id, 'member_id' => $member->id, 'kind' => 'self', 'state' => 'revoked', 'revision' => 0, 'reason' => 'Outdated review fixture']);
    }

    public function test_testmail_is_idempotent_and_tenant_scoped(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.smtp.host' => 'fixture.invalid',
            'clubunio-integrations.from' => 'test@example.invalid', 'mail.mailers.clubunio_test' => ['transport' => 'array']]);
        app(MessageTemplateService::class)->seed($this->own);
        $template = MessageTemplate::where('tenant_id', $this->own->id)->where('channel', 'email')->firstOrFail();
        $service = app(TestMailService::class);
        $key = (string) Str::uuid();
        $this->assertSame('accepted', $service->sendPreview($this->actor, $this->own, $template->id, $key));
        $this->assertSame('accepted', $service->sendPreview($this->actor, $this->own, $template->id, $key));
        $this->assertSame(1, $this->db->table('clubora.test_mail_attempts')->where('tenant_id', $this->own->id)->count());
        $this->assertCount(1, Mail::mailer('clubunio_test')->getSymfonyTransport()->messages());
        $this->expectException(HttpException::class);
        $service->sendPreview($this->actor, $this->other, $template->id, (string) Str::uuid());
    }

    public function test_document_download_requires_tenant_access_and_serves_safe_headers(): void
    {
        config(['clubunio-integrations.azure.tenant_slug' => $this->own->slug]);
        $type = $this->db->table('clubora.document_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture', 'name' => 'Fixture']);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $id = $this->db->table('clubora.member_documents')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id,
            'document_type_id' => $type, 'filename' => 'fixture.pdf', 'storage_path' => 'var/member-documents/', 'original_filename' => 'Prüfung.pdf']);
        $reader = \Mockery::mock(AzureDocumentReader::class);
        $reader->shouldReceive('read')->once()->with('var/member-documents/', 'fixture.pdf')->andReturn("%PDF-1.4\nfixture");
        $this->app->instance(AzureDocumentReader::class, $reader);
        $this->get('/clubora-documents/'.$this->other->slug.'/'.$id)->assertForbidden();
        $this->get('/clubora-documents/'.$this->own->slug.'/'.$id)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->db->table('clubora.member_documents')->where('id', $id)->update(['active' => false]);
        $this->get('/clubora-documents/'.$this->own->slug.'/'.$id)->assertNotFound();
        auth('clubunio')->logout();
        $this->get('/clubora-documents/'.$this->own->slug.'/'.$id)->assertRedirect('/login');
    }

    public function test_message_templates_seed_edit_restore_and_locale_fallback(): void
    {
        $service = app(MessageTemplateService::class);
        $service->seed($this->own);
        $query = MessageTemplate::where('tenant_id', $this->own->id)->where('event_key', 'expense.review')->where('channel', 'push');
        $template = $query->firstOrFail();
        $service->save($this->actor, $this->own, $template->id, ['title' => 'Kontrolle {{ mitglied }}', 'body' => 'Saison {{ saison }}', 'active' => true, 'revision' => 1]);
        $service->seed($this->own);
        $this->assertSame(2, $query->firstOrFail()->revision);
        $rendered = $service->render($this->own, 'expense.review', 'push', ['mitglied' => 'Alex', 'saison' => '2026/2027'], 'fr-CH');
        $this->assertSame('Kontrolle Alex', $rendered['title']);
        $this->assertSame('de-CH', $rendered['locale']);
        $this->assertSame(2, $rendered['revision']);
        $service->restore($this->actor, $this->own, $template->id, 1, 2);
        $this->assertSame(3, $query->firstOrFail()->revision);
        $this->assertSame($template->title, $query->firstOrFail()->title);
        $this->assertSame(3, $template->revisions()->count());
        $french = $service->save($this->actor, $this->own, null, ['event_key' => 'expense.review', 'channel' => 'push', 'locale' => 'fr-CH', 'title' => 'Contrôle', 'body' => 'Bonjour', 'active' => false]);
        $this->assertNull($service->render($this->own, 'expense.review', 'push', [], 'fr-CH'));
        $this->assertSame($this->own->id, $french->tenant_id);
    }

    public function test_template_rejects_executable_content_unknown_placeholders_and_lost_updates(): void
    {
        $service = app(MessageTemplateService::class);
        $data = ['event_key' => 'announcement.manual', 'channel' => 'push', 'locale' => 'de-CH', 'title' => 'News', 'body' => '{{ nachricht }}', 'active' => true];
        foreach (['{{ password }}', '{{ file_get_contents(".env") }}', '<script>alert(1)</script>', '@php echo 123; @endphp', '{{{ titel }}}'] as $invalid) {
            try {
                $service->save($this->actor, $this->own, null, array_replace($data, ['body' => $invalid]));
                $this->fail('Unsafe template accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body', $e->errors());
            }
        }
        $template = $service->save($this->actor, $this->own, null, $data);
        try {
            $service->save($this->actor, $this->own, $template->id, $data + ['revision' => 0]);
            $this->fail('Stale revision accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }
        $this->assertSame(1, $template->fresh()->revision);
        $rendered = $service->preview('announcement.manual', '{{ titel }}', '{{ nachricht }}', ['titel' => "Hello\r\nBcc: test", 'nachricht' => '{{ titel }}']);
        $this->assertSame('Hello  Bcc: test', $rendered['title']);
        $this->assertSame('{{ titel }}', $rendered['body']);
        $this->expectException(ValidationException::class);
        $service->preview('announcement.manual', '{{ titel }}', '{{ nachricht }}', []);
    }

    public function test_template_service_rejects_other_tenants_and_non_office_accounts(): void
    {
        $service = app(MessageTemplateService::class);
        $service->seed($this->other);
        $foreign = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $this->other->id)->firstOrFail();
        $data = ['title' => 'No', 'body' => 'No', 'active' => true, 'revision' => 1];
        try {
            $service->save($this->actor, $this->own, $foreign->id, $data);
            $this->fail('Other tenant edited');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'member']);
        try {
            $service->save($this->actor, $this->own, null, $data);
            $this->fail('Member edited template');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_template_editor_and_preview_escape_content_and_preserve_filters(): void
    {
        $service = app(MessageTemplateService::class);
        $data = ['event_key' => 'announcement.manual', 'channel' => 'push', 'locale' => 'de-CH', 'title' => 'Fixture news', 'body' => '{{ nachricht }}', 'active' => true];
        $template = $service->save($this->actor, $this->own, null, $data);
        $this->get('/message-templates')->assertOk()->assertSee('Fixture news');
        Livewire::test(ListMessageTemplates::class)
            ->filterTable('channel', 'push')->searchTable('Fixture')->assertCanSeeTableRecords([$template])
            ->mountTableAction('change', $template)
            ->setTableActionData(['title' => 'Neue Nachricht', 'body' => '{{ nachricht }}', 'active' => true, 'revision' => 1])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Neue Nachricht', $template->fresh()->title);
        $html = view('clubunio.messages.editor-preview', ['variables' => ['nachricht'], 'error' => null, 'preview' => ['title' => 'Test', 'body' => '<script>alert(1)</script>']])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_expense_preview_routes_review_to_office_and_assigned_tl_and_other_states_to_owner(): void
    {
        Http::fake();
        Mail::fake();
        app(MessageTemplateService::class)->seed($this->own);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => '2026/2027']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Junioren']);
        $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Junioren / 2026']);
        foreach ([['Assigned TL', true, $ts], ['Inactive TL', false, $ts]] as [$name, $active, $teamSeason]) {
            $mid = $this->db->table('members')->insertGetId(['tenant_id' => $this->own->id, 'first_name' => $name]);
            $this->db->table('technical_leads')->insert(['tenant_id' => $this->own->id, 'member_id' => $mid, 'team_season_id' => $teamSeason, 'active' => $active]);
        }
        $office = new Account;
        $office->forceFill(['username' => 'office-'.bin2hex(random_bytes(6)), 'display_name' => 'Office', 'password' => '-', 'active' => true])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->own->id, 'account_id' => $office->id, 'role' => 'office']);
        $status = $this->db->table('expense_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'F', 'name' => 'Freigegeben']);
        $expense = $this->db->table('expenses')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $ts, 'season_id' => $season, 'status_id' => $status]);
        $this->db->table('expense_lines')->insert(['tenant_id' => $this->own->id, 'expense_id' => $expense, 'amount' => '85.00']);
        $service = app(ExpenseNotificationService::class);
        $plan = $service->preview($this->actor, $this->own, $expense);
        $this->assertSame(['Geschäftsstelle', 'Technische Leitung'], array_column($plan['recipients'], 'role'));
        $this->assertStringContainsString('CHF 85.00', $plan['messages']['push']['body']);
        $this->assertStringContainsString('2026/2027', $plan['messages']['push']['body']);
        $this->assertStringNotContainsString('Inactive TL', json_encode($plan));
        $this->db->table('expense_statuses')->where('id', $status)->update(['code' => 'B']);
        $plan = $service->preview($this->actor, $this->own, $expense);
        $this->assertSame([$member->id], array_column($plan['recipients'], 'id'));
        $this->assertSame('expense.paid', $plan['event']);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_member_notes_can_be_edited_and_archived_without_losing_history(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $service = app(MemberNoteService::class);
        $note = $service->save($this->actor, $this->own, $member->id, null, ['body' => 'Initial note']);
        $service->save($this->actor, $this->own, $member->id, $note->id, ['body' => 'Revised note', 'active' => false, 'revision' => 1]);
        $this->assertFalse($note->fresh()->active);
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'member_notes')->count());
        try {
            $service->save($this->actor, $this->own, $member->id, $note->id, ['body' => 'Stale', 'revision' => 1]);
            $this->fail('Stale note accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
        $otherMember = new Member;
        $otherMember->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Other'])->save();
        $this->expectException(ModelNotFoundException::class);
        $service->save($this->actor, $this->own, $otherMember->id, $note->id, ['body' => 'Moved', 'revision' => 2]);
    }

    public function test_note_editor_saves_in_member_dossier(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $note = app(MemberNoteService::class)->save($this->actor, $this->own, $member->id, null, ['body' => 'Initial note']);
        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $member, 'pageClass' => ViewMember::class])
            ->loadTable()->mountTableAction('changeNote', $note)
            ->setTableActionData(['body' => 'Updated in dossier', 'active' => true, 'revision' => 1])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('Updated in dossier', $note->fresh()->body);
    }

    public function test_all_message_defaults_render_and_locale_creation_is_guarded(): void
    {
        $service = app(MessageTemplateService::class);
        $service->seed($this->own);
        foreach (MessageEventCatalog::all() as $key => $event) {
            foreach ($event['channels'] as $channel) {
                $message = $service->render($this->own, $key, $channel, MessageEventCatalog::samples());
                $this->assertNotNull($message);
                $this->assertStringNotContainsString('{{', $message['body']);
            }
        }
        $data = ['event_key' => 'announcement.manual', 'channel' => 'push', 'locale' => 'en-GB', 'title' => 'Announcement', 'body' => '{{ nachricht }}', 'active' => true];
        Livewire::test(ListMessageTemplates::class)
            ->callTableAction('newVariant', data: $data)->assertHasNoTableActionErrors();
        $this->assertSame(1, MessageTemplate::where('tenant_id', $this->own->id)->where('locale', 'en-GB')->count());
        foreach ([$data, array_replace($data, ['event_key' => 'registration.review', 'channel' => 'email'])] as $invalid) {
            try {
                $service->save($this->actor, $this->own, null, $invalid);
                $this->fail('Duplicate or forbidden channel accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_note_service_rejects_other_tenants_and_members_without_office_role(): void
    {
        $service = app(MemberNoteService::class);
        $foreign = Member::where('tenant_id', $this->other->id)->firstOrFail();
        try {
            $service->save($this->actor, $this->own, $foreign->id, null, ['body' => 'Forbidden']);
            $this->fail('Foreign member accepted');
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
        $own = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'member']);
        try {
            $service->save($this->actor, $this->own, $own->id, null, ['body' => 'Forbidden']);
            $this->fail('Member role accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_member_dossier_and_all_relation_tabs_render(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $this->get('/members/'.$member->id)->assertOk()->assertSee('Stammdaten')->assertSee('Rechnungen')->assertSee('Bankkonten');
        foreach (MemberResource::getRelations() as $manager) {
            Livewire::test($manager, ['ownerRecord' => $member, 'pageClass' => ViewMember::class])
                ->loadTable()->assertSuccessful();
        }
        $foreign = Member::withoutGlobalScopes()->where('tenant_id', $this->other->id)->firstOrFail();
        $this->get('/members/'.$foreign->id)->assertNotFound();
        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $foreign, 'pageClass' => ViewMember::class])->assertForbidden();
    }

    public function test_member_relations_do_not_leak_other_members_or_tenants(): void
    {
        $own = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'SECOND MEMBER'])->save();
        $foreign = Member::where('tenant_id', $this->other->id)->firstOrFail();
        foreach ([$own, $other, $foreign] as $member) {
            $this->db->table('member_notes')->insert(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'body' => 'Note-'.$member->id]);
        }
        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $own, 'pageClass' => ViewMember::class])
            ->loadTable()->assertSee('Note-'.$own->id)->assertDontSee('Note-'.$other->id)->assertDontSee('Note-'.$foreign->id)
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
    }

    public function test_invoice_and_expense_details_include_only_their_positions(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        foreach ([['invoices', 'invoice_lines', 'invoice_id', 'net_amount', 'description', 'Invoice'], ['expenses', 'expense_lines', 'expense_id', 'amount', 'notes', 'Expense']] as [$table,$lines,$fk,$amount,$text,$name]) {
            $id = $this->db->table($table)->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'legacy_id' => 9991]);
            $other = $this->db->table($table)->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'legacy_id' => 9992]);
            foreach ([[$id, true, 'VISIBLE', 25], [$id, false, 'ARCHIVED', 100], [$other, true, 'UNRELATED', 500]] as [$parent,$active,$label,$value]) {
                $this->db->table($lines)->insert(['tenant_id' => $this->own->id, $fk => $parent, $amount => $value, $text => $label, 'active' => $active]);
            }
            $this->get('/'.$table)->assertOk();
            $this->get('/'.$table.'/'.$id)->assertOk()->assertSee('25.00');
            $class = 'App\\ClubUnio\\Models\\'.$name;
            $record = $class::findOrFail($id);
            Livewire::test('App\\ClubUnio\\Resources\\Relations\\'.$name.'LinesRelationManager', [
                'ownerRecord' => $record, 'pageClass' => 'App\\ClubUnio\\Resources\\'.$name.'Resource\\Pages\\View'.$name])
                ->loadTable()->assertSee('VISIBLE')->assertDontSee('ARCHIVED')->assertDontSee('UNRELATED')
                ->assertTableActionDoesNotExist('edit');
            $foreignMember = Member::withoutGlobalScopes()->where('tenant_id', $this->other->id)->firstOrFail();
            $foreignId = $this->db->table($table)->insertGetId(['tenant_id' => $this->other->id, 'member_id' => $foreignMember->id]);
            $this->get('/'.$table.'/'.$foreignId)->assertNotFound();
        }
    }

    public function test_bank_accounts_are_masked_and_qualification_lookup_is_resolved(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $id = $this->db->table('member_bank_accounts')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $bank = MemberBankAccount::findOrFail($id);
        $this->assertArrayNotHasKey('iban_encrypted', $bank->toArray());
        Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => ViewMember::class])
            ->loadTable()->assertSee('2957')->assertDontSee('CH930076201162385');
        $function = $this->db->table('member_functions')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id]);
        $qualification = $this->db->table('qualifications')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'QUALIFICATION-FIXTURE']);
        $this->db->table('member_function_qualifications')->insert(['tenant_id' => $this->own->id, 'member_function_id' => $function, 'qualification_id' => $qualification]);
        Livewire::test(FunctionsRelationManager::class, ['ownerRecord' => $member, 'pageClass' => ViewMember::class])
            ->loadTable()->assertSee('QUALIFICATION-FIXTURE');
    }

    public function test_pmp_inventory_contains_relations_and_dependent_lookups(): void
    {
        $inventory = json_decode(file_get_contents(base_path('deploy/pmp-relations.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(13, array_filter($inventory['relations'], fn ($r) => $r['master'] === 'Mitglieder'));
        $field = collect($inventory['tables']['Spesenbelege'])->firstWhere('FldName', 'BankkontoStaffID');
        $this->assertSame('MitgliederID', $field['FldParentSelect']);
        $this->assertSame('BankkontoStaff', $field['FldTagLnkTbl']);
    }

    public function test_family_team_and_season_master_pages_and_children(): void
    {
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'SEASON-FIXTURE']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'TEAM-FIXTURE']);
        $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'TEAM / SEASON']);
        $family = $this->db->table('families')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'FAMILY-FIXTURE']);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $this->db->table('team_players')->insert(['tenant_id' => $this->own->id, 'team_season_id' => $ts, 'member_id' => $member->id]);
        $this->db->table('family_members')->insert(['tenant_id' => $this->own->id, 'family_id' => $family, 'member_id' => $member->id, 'discount' => 10]);
        foreach ([['families', $family, 'Family'], ['teams', $team, 'Team'], ['seasons', $season, 'Season'], ['team-seasons', $ts, 'TeamSeason']] as [$slug,$id,$name]) {
            $this->get('/'.$slug.'/'.$id)->assertOk();
            $class = 'App\\ClubUnio\\Resources\\'.$name.'Resource';
            $model = 'App\\ClubUnio\\Models\\'.$name;
            foreach ($class::getRelations() as $manager) {
                Livewire::test($manager, ['ownerRecord' => $model::findOrFail($id), 'pageClass' => $class.'\\Pages\\View'.$name])->loadTable()->assertSuccessful();
            }
        }
    }

    public function test_invoice_filter_and_search_work_together_and_finance_is_read_only(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $status = $this->db->table('invoice_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture', 'name' => 'Fixture']);
        $id = $this->db->table('invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'status_id' => $status, 'legacy_id' => 444]);
        $other = $this->db->table('invoices')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'legacy_id' => 445]);
        $invoice = Invoice::findOrFail($id);
        Livewire::test(ListInvoices::class)
            ->filterTable('status_id', $status)->searchTable('OWN-MEMBER')->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords([Invoice::findOrFail($other)])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
        $this->assertFalse(Gate::forUser($this->actor)->allows('update', $invoice));
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'member']);
        $this->assertFalse(Gate::forUser($this->actor)->allows('view', $invoice));
    }

    public function test_settings_are_masked_and_changes_encrypted_and_audited(): void
    {
        $this->get('/settings')->assertOk()->assertDontSee('secret-fixture');
        $id = $this->db->table('tenant_settings')->where('tenant_id', $this->own->id)->value('id');
        app(SettingsService::class)->update($this->actor, $this->own, $id, 'replacement-fixture');
        $cipher = $this->db->table('tenant_settings')->where('id', $id)->value('encrypted_value');
        $this->assertNotSame('replacement-fixture', $cipher);
        $this->assertSame('replacement-fixture', Crypt::decryptString($cipher));
        $this->assertSame(1, $this->db->table('setting_changes')->where('setting_id', $id)->count());
        $this->assertArrayNotHasKey('encrypted_value', Setting::findOrFail($id)->toArray());
    }

    public function test_foreign_setting_id_cannot_be_changed(): void
    {
        $id = $this->db->table('tenant_settings')->where('tenant_id', $this->other->id)->value('id');
        $this->expectException(ModelNotFoundException::class);
        app(SettingsService::class)->update($this->actor, $this->own, $id, 'forbidden');
    }

    public function test_inactive_and_member_accounts_are_denied(): void
    {
        $panel = Filament::getPanel('clubunio');
        $this->assertTrue($this->actor->canAccessPanel($panel));
        $this->actor->active = false;
        $this->assertFalse($this->actor->canAccessTenant($this->own));
        $this->assertFalse($this->actor->canAccessPanel($panel));
        $this->actor->active = true;
        $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'member']);
        $this->assertFalse($this->actor->canAccessPanel($panel));
        $this->expectException(HttpException::class);
        app(SettingsService::class)->update($this->actor, $this->own, 0, 'forbidden');
    }

    public function test_invalid_integer_is_rejected_without_audit_entry(): void
    {
        $id = $this->db->table('tenant_settings')->where('tenant_id', $this->own->id)->value('id');
        $this->db->table('tenant_settings')->where('id', $id)->update(['value_type' => 'integer']);
        try {
            app(SettingsService::class)->update($this->actor, $this->own, $id, 'not-an-integer');
            $this->fail('Invalid setting accepted');
        } catch (ValidationException) {
            $this->assertSame(0, $this->db->table('setting_changes')->where('setting_id', $id)->count());
        }
    }

    public function test_database_rejects_cross_tenant_audit_reference(): void
    {
        $foreign = $this->db->table('tenant_settings')->where('tenant_id', $this->other->id)->value('id');
        try {
            $this->db->transaction(fn () => $this->db->table('setting_changes')->insert([
                'tenant_id' => $this->own->id, 'setting_id' => $foreign, 'actor_id' => $this->actor->id,
            ]));
            $this->fail('Foreign tenant reference accepted');
        } catch (QueryException $e) {
            $this->assertSame('23503', (string) $e->getCode());
        }
    }

    public function test_login_uses_the_separate_clubora_guard(): void
    {
        auth('clubunio')->logout();
        Livewire::test(Login::class)
            ->fillForm(['email' => $this->actor->username, 'password' => 'fixture-password'])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->actor, 'clubunio');
    }

    public function test_catalog_pages_render_and_new_lookup_is_used_by_member_filter(): void
    {
        $service = app(CatalogService::class);
        $status = $service->save($this->actor, $this->own, MemberStatus::class, null,
            ['name' => 'Custom member status', 'code' => 'custom', 'sort_order' => 15, 'active' => true]);
        $this->db->table('members')->where('tenant_id', $this->own->id)->update(['status_id' => $status->id]);
        foreach (['teams', 'team-seasons', 'seasons', 'registrations', 'member-statuses', 'member-types', 'registration-statuses'] as $slug) {
            $this->get('/'.$slug)->assertOk();
        }
        Livewire::test(ListMembers::class)
            ->set('tableFilters.status_id.value', (string) $status->id)->searchTable('OWN-MEMBER')
            ->assertSee('OWN-MEMBER')->assertSee('Custom member status')
            ->assertSet('tableFilters.status_id.value', (string) $status->id);
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->count());
    }

    public function test_catalog_edit_cannot_target_another_tenant(): void
    {
        $id = $this->db->table('teams')->insertGetId(['tenant_id' => $this->other->id, 'name' => 'Foreign team']);
        $this->expectException(ModelNotFoundException::class);
        app(CatalogService::class)->save($this->actor, $this->own, Team::class, $id, ['name' => 'Changed', 'active' => true]);
    }

    public function test_status_codes_are_immutable_and_tenant_input_is_rejected(): void
    {
        $service = app(CatalogService::class);
        $status = $service->save($this->actor, $this->own, RegistrationStatus::class, null,
            ['name' => 'Fixture status', 'code' => 'fixture', 'sort_order' => 0, 'active' => true]);
        $this->expectException(ValidationException::class);
        $service->save($this->actor, $this->own, RegistrationStatus::class, $status->id,
            ['name' => 'Changed', 'code' => 'Akzeptiert', 'tenant_id' => $this->other->id, 'sort_order' => 0, 'active' => true]);
    }

    public function test_registration_and_team_filters_use_labels_and_are_read_only(): void
    {
        $s = $this->db->table('registration_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture', 'name' => 'Trial fixture']);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Test team']);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => '2026/2027']);
        $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Test team / 2026/2027']);
        $id = $this->db->table('registrations')->insertGetId(['tenant_id' => $this->own->id, 'legacy_id' => 999999, 'first_name' => 'Test application', 'status_id' => $s, 'team_season_id' => $ts]);
        $page = Livewire::test(ListRegistrations::class)
            ->set('tableFilters.status_id.value', (string) $s)->searchTable('Test application')
            ->assertSee('Trial fixture')->assertSee('Test team / 2026/2027')
            ->assertSet('tableFilters.status_id.value', (string) $s);
        $this->assertSame(1, $page->instance()->getFilteredTableQuery()->count());
        $page->assertTableActionVisible('view', $id)->mountTableAction('view', $id)
            ->assertSet('mountedActions.0.name', 'view')->assertSet('mountedActions.0.context.recordKey', $id);
        $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
        $this->assertContains('Anmeldung-Nr.', array_map(fn ($c) => method_exists($c, 'getLabel') ? $c->getLabel() : null, $schema->getFlatComponents()));
        $this->assertFalse(RegistrationResource::canCreate());
    }

    public function test_cross_tenant_team_season_is_rejected_by_database(): void
    {
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->other->id, 'name' => 'Other']);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture']);
        try {
            $this->db->transaction(fn () => $this->db->table('team_seasons')->insert([
                'tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Forbidden']));
            $this->fail('Cross-tenant team accepted');
        } catch (QueryException $e) {
            $this->assertSame('23503', (string) $e->getCode());
        }
    }

    public function test_team_creation_through_filament_action_is_audited(): void
    {
        Livewire::test(ManageTeams::class)
            ->callTableAction('create', data: ['name' => 'New fixture team', 'description' => 'Fixture', 'sfv_team_id' => null, 'laundry_allowance' => '35.00', 'active' => true])
            ->assertHasNoTableActionErrors()->assertSee('New fixture team');
        $this->assertSame(1, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->where('object_type', 'teams')->count());
    }

    private function memberInput($member): array
    {
        return $member->only(['first_name', 'last_name', 'company', 'email', 'city', 'status_id', 'type_id'])
            + ['birth_date' => $member->birth_date?->format('Y-m-d'), '_version' => MemberService::version($member)];
    }

    public function test_clubora_runtime_ignores_a_different_pool_search_path(): void
    {
        $this->db->statement('SET LOCAL search_path TO public');
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $this->assertTrue($this->actor->canAccessTenant($this->own));
        $this->get('/members')->assertOk()->assertSee('OWN-MEMBER');
        $member = app(MemberService::class)->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['city' => 'Schema-safe']));
        $this->assertSame('Schema-safe', $member->city);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->own->id)->count());
    }

    public function test_extended_member_pages_and_country_form(): void
    {
        foreach (['countries', 'genders', 'billing-recipients', 'document-types', 'families', 'family-members', 'member-documents'] as $slug) {
            $this->get('/'.$slug)->assertOk();
            $this->get('/clubunio/'.$this->other->slug.'/'.$slug)->assertNotFound();
        }
        Livewire::test(ManageCountries::class)
            ->callTableAction('create', data: ['code' => 'CH', 'iso3' => 'CHE', 'name' => 'Schweiz', 'sort_order' => 1, 'active' => true])->assertHasNoTableActionErrors()->assertSee('Schweiz');
    }

    public function test_team_assignment_ui_preserves_filters_and_rejects_duplicates(): void
    {
        foreach (['team-players', 'team-staff', 'technical-leads', 'staff-functions'] as $slug) {
            $this->get('/'.$slug)->assertOk();
        }
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Team fixture']);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => '2026/2027']);
        Livewire::test(ListTeamSeasons::class)
            ->callTableAction('create', data: ['team_id' => $team, 'season_id' => $season, 'active' => true])->assertHasNoTableActionErrors();
        $ts = $this->db->table('team_seasons')->where('tenant_id', $this->own->id)->first();
        $this->assertSame('Team fixture / 2026/2027', $ts->label);
        Livewire::test(ManageTeamPlayers::class)
            ->callTableAction('create', data: ['team_season_id' => $ts->id, 'member_id' => $member->id, 'active' => true])->assertHasNoTableActionErrors()
            ->set('tableFilters.season.value', (string) $season)->searchTable('OWN-MEMBER')->assertSee('Team fixture')->assertSet('tableFilters.season.value', (string) $season);
        $this->expectException(ValidationException::class);
        app(TeamAssignmentService::class)->save($this->actor, $this->own, TeamPlayer::class, null,
            ['member_id' => $member->id, 'team_season_id' => $ts->id, 'active' => true]);
    }

    public function test_team_assignment_protects_foreign_members_and_invoice_links(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $foreign = Member::where('tenant_id', $this->other->id)->firstOrFail();
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Team fixture']);
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->own->id, 'name' => 'Fixture']);
        $ts = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->own->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Fixture']);
        $service = app(TeamAssignmentService::class);
        try {
            $service->save($this->actor, $this->own, TeamPlayer::class, null, ['member_id' => $foreign->id, 'team_season_id' => $ts, 'active' => true]);
            $this->fail('Foreign member accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $id = $this->db->table('team_players')->insertGetId(['tenant_id' => $this->own->id, 'member_id' => $member->id, 'team_season_id' => $ts, 'legacy_invoice_position_id' => 123]);
        $record = TeamPlayer::findOrFail($id);
        $other = new Member;
        $other->forceFill(['tenant_id' => $this->own->id, 'first_name' => 'Other', 'last_name' => 'Fixture'])->save();
        try {
            $service->save($this->actor, $this->own, TeamPlayer::class, $id, ['member_id' => $other->id, 'team_season_id' => $ts, 'active' => true, '_version' => FamilyService::version($record)]);
            $this->fail('Invoice owner reassigned');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $service->save($this->actor, $this->own, TeamPlayer::class, $id, ['member_id' => $member->id, 'team_season_id' => $ts, 'active' => false, '_version' => FamilyService::version($record)]);
        $this->assertFalse($record->fresh()->active);
        $this->assertSame(123, $record->fresh()->legacy_invoice_position_id);
    }

    public function test_member_creation_recipient_validation_and_reentry(): void
    {
        $status = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'active', 'name' => 'Mitglied', 'restores_membership' => true]);
        $exit = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'exit', 'name' => 'Ausgetreten', 'ends_membership' => true]);
        $type = $this->db->table('member_types')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'a', 'name' => 'Aktiv']);
        $billing = $this->db->table('billing_recipients')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'M', 'name' => 'Mutter', 'email_field' => 'mother_email']);
        $data = ['company' => 'New Company', 'first_name' => null, 'last_name' => null, 'status_id' => $status, 'type_id' => $type,
            'billing_recipient_id' => $billing, 'mother_email' => 'parent@example.com', 'postal_code' => '00445', 'pass_number' => '12345'];
        Livewire::test(ListMembers::class)
            ->callTableAction('create', data: array_merge($data, ['mother_email' => null]))->assertHasTableActionErrors(['mother_email']);
        Livewire::test(ListMembers::class)
            ->callTableAction('create', data: $data)->assertHasNoTableActionErrors()->assertSee('New Company');
        $member = Member::where('tenant_id', $this->own->id)->where('company', 'New Company')->firstOrFail();
        $this->assertNull($member->legacy_id);
        $this->assertGreaterThan(0, $member->member_number);
        $this->assertSame('00445', $member->postal_code);
        $service = app(MemberService::class);
        try {
            $service->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['mother_email' => '']));
            $this->fail('Missing billing address accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mother_email', $e->errors());
        }
        $member = $service->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['status_id' => $exit]));
        $this->assertSame(now('Europe/Zurich')->toDateString(), $member->exit_date->toDateString());
        $member = $service->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['status_id' => $status]));
        $this->assertNull($member->exit_date);
    }

    public function test_family_links_are_audited_unique_and_tenant_scoped(): void
    {
        $service = app(FamilyService::class);
        $family = $service->save($this->actor, $this->own, Family::class, null, ['name' => 'Fixture family', 'active' => true]);
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $data = ['family_id' => $family->id, 'member_id' => $member->id, 'discount' => '20.00', 'notes' => 'Fixture', 'active' => true];
        Livewire::test(ManageFamilyMembers::class)
            ->callTableAction('create', data: $data)->assertHasNoTableActionErrors()->searchTable('OWN-MEMBER')->assertSee('Fixture family');
        try {
            $service->save($this->actor, $this->own, FamilyMember::class, null, $data);
            $this->fail('Duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $data['member_id'] = Member::where('tenant_id', $this->other->id)->value('id');
        try {
            $service->save($this->actor, $this->own, FamilyMember::class, null, $data);
            $this->fail('Foreign member accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $this->assertSame(2, $this->db->table('object_changes')->where('tenant_id', $this->own->id)->count());
    }

    public function test_member_archive_hides_and_blocks_edits_without_erasing_data(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $service = app(MemberService::class);
        $service->archive($this->actor, $this->own, $member->id, MemberService::version($member));
        $this->get('/members')->assertOk()->assertDontSee('OWN-MEMBER');
        $this->assertNotNull($member->fresh()->archived_at);
        $this->expectException(ModelNotFoundException::class);
        $service->update($this->actor, $this->own, $member->id, $this->memberInput($member));
    }

    public function test_audit_page_is_read_only_and_member_sensitive_fields_are_hidden(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        app(MemberService::class)->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['city' => 'Audit fixture']));
        $this->get('/object-changes')->assertOk()->assertSee('Test Admin');
        $this->get('/clubunio/'.$this->other->slug.'/object-changes')->assertNotFound();
        $this->assertFalse(ObjectChangeResource::canCreate());
        $this->assertArrayNotHasKey('ahv_encrypted', $member->fresh()->toArray());
    }

    public function test_member_edit_through_ui_preserves_filters_and_audits(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $status = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'fixture', 'name' => 'New status']);
        $page = Livewire::test(ListMembers::class)
            ->searchTable('Fixture')->callTableAction('edit', $member, data: array_merge($this->memberInput($member), [
                'first_name' => 'Updated', 'email' => 'fixture@example.com', 'birth_date' => '2000-01-01', 'status_id' => $status,
            ]))->assertHasNoTableActionErrors()->assertSee('Updated')->assertSet('tableSearch', 'Fixture');
        $this->assertSame('Updated', $member->fresh()->first_name);
        $audit = $this->db->table('object_changes')->where('object_type', 'members')->where('object_id', $member->id)->first();
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertSame('OWN-MEMBER', json_decode($audit->before_value, true)['first_name']);
    }

    public function test_member_company_rule_and_concurrent_edit_protection(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $service = app(MemberService::class);
        $input = $this->memberInput($member);
        try {
            $service->update($this->actor, $this->own, $member->id, array_merge($input, ['company' => ' ', 'first_name' => '', 'last_name' => '']));
            $this->fail('Empty person accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('first_name', $e->errors());
        }
        $service->update($this->actor, $this->own, $member->id, array_merge($input, ['company' => 'Fixture AG', 'first_name' => '', 'last_name' => '']));
        $this->assertSame('Fixture AG', $member->fresh()->company);
        try {
            $service->update($this->actor, $this->own, $member->id, $input);
            $this->fail('Stale edit accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('company', $e->errors());
        }
        $this->assertSame(1, $this->db->table('object_changes')->where('object_type', 'members')->where('object_id', $member->id)->count());
    }

    public function test_member_rejects_foreign_or_inactive_lookups_and_protected_fields(): void
    {
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        $foreign = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->other->id, 'code' => 'f', 'name' => 'Foreign']);
        $inactive = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->own->id, 'code' => 'i', 'name' => 'Inactive', 'active' => false]);
        $service = app(MemberService::class);
        foreach ([['status_id' => $foreign], ['status_id' => $inactive], ['tenant_id' => $this->other->id], ['legacy_id' => 1], ['email' => 'invalid'], ['birth_date' => '2999-01-01']] as $invalid) {
            try {
                $service->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), $invalid));
                $this->fail('Invalid input accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(array_key_first($invalid), $e->errors());
            }
        }
        $this->db->table('members')->where('id', $member->id)->update(['status_id' => $inactive]);
        $member->refresh();
        $service->update($this->actor, $this->own, $member->id, array_merge($this->memberInput($member), ['city' => 'Test city']));
        $this->assertSame($inactive, $member->fresh()->status_id);
    }

    public function test_member_service_and_policy_reject_foreign_members_and_non_office_roles(): void
    {
        $service = app(MemberService::class);
        $foreign = Member::where('tenant_id', $this->other->id)->firstOrFail();
        $this->assertFalse(Gate::forUser($this->actor)->allows('update', $foreign));
        try {
            $service->update($this->actor, $this->own, $foreign->id, $this->memberInput($foreign));
            $this->fail('Foreign member accepted');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $member = Member::where('tenant_id', $this->own->id)->firstOrFail();
        foreach (['office', 'member'] as $role) {
            $this->db->table('tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => $role]);
            $this->assertSame($role === 'office', Gate::forUser($this->actor)->allows('update', $member));
        }
        $this->expectException(HttpException::class);
        $service->update($this->actor, $this->own, $member->id, $this->memberInput($member));
    }

    public function test_lookup_label_edit_keeps_code_and_can_disable_without_deletion(): void
    {
        $status = app(CatalogService::class)->save($this->actor, $this->own, MemberStatus::class, null,
            ['name' => 'Original', 'code' => 'fixture', 'sort_order' => 1, 'active' => true]);
        Livewire::test(ManageMemberStatuses::class)
            ->callTableAction('edit', $status, data: ['name' => 'Renamed', 'sort_order' => 2, 'active' => false])->assertHasNoTableActionErrors();
        $status->refresh();
        $this->assertSame('fixture', $status->code);
        $this->assertSame('Renamed', $status->name);
        $this->assertFalse($status->active);
    }
}
