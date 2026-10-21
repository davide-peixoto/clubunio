<?php

namespace App\Providers\Filament;

use App\ClubUnio\Http\UseActiveTenant;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\BexioDispatchTrials;
use App\ClubUnio\Pages\BexioReconciliation;
use App\ClubUnio\Pages\BexioSandbox;
use App\ClubUnio\Pages\BusReservations;
use App\ClubUnio\Pages\ContributionPreview;
use App\ClubUnio\Pages\Login;
use App\ClubUnio\Pages\SeasonPlanning;
use App\ClubUnio\Pages\SettingsOverview;
use App\ClubUnio\Pages\SfvCompetitions;
use App\ClubUnio\Pages\SignagePreview;
use App\ClubUnio\Pages\Synchronisation;
use App\ClubUnio\Pages\TeamPlanning;
use App\ClubUnio\Resources\AccountMemberLinkResource;
use App\ClubUnio\Resources\AnnouncementResource;
use App\ClubUnio\Resources\BankAccountReportResource;
use App\ClubUnio\Resources\BankResource;
use App\ClubUnio\Resources\BexioCatalogEntryResource;
use App\ClubUnio\Resources\BillingRecipientResource;
use App\ClubUnio\Resources\BusKeyboxResource;
use App\ClubUnio\Resources\ClubEventResource;
use App\ClubUnio\Resources\ClubFunctionResource;
use App\ClubUnio\Resources\CompetitionResource;
use App\ClubUnio\Resources\ContributionPriceResource;
use App\ClubUnio\Resources\ContributionRuleResource;
use App\ClubUnio\Resources\CountryResource;
use App\ClubUnio\Resources\CreditNoteResource;
use App\ClubUnio\Resources\CustomerCreditResource;
use App\ClubUnio\Resources\DocumentTypeResource;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Resources\ExpenseRoundResource;
use App\ClubUnio\Resources\ExpenseStatusResource;
use App\ClubUnio\Resources\ExpenseTypeResource;
use App\ClubUnio\Resources\FacilityStatusResource;
use App\ClubUnio\Resources\FamilyMemberResource;
use App\ClubUnio\Resources\FamilyReportResource;
use App\ClubUnio\Resources\FamilyResource;
use App\ClubUnio\Resources\FieldAvailabilityResource;
use App\ClubUnio\Resources\FinancialAdjustmentStatusResource;
use App\ClubUnio\Resources\FootballClubResource;
use App\ClubUnio\Resources\GameTypeResource;
use App\ClubUnio\Resources\GenderResource;
use App\ClubUnio\Resources\InvoiceCategoryResource;
use App\ClubUnio\Resources\InvoiceLineStatusResource;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Resources\InvoiceStatusResource;
use App\ClubUnio\Resources\InvoiceWorkflowStatusResource;
use App\ClubUnio\Resources\KeyReportResource;
use App\ClubUnio\Resources\LegacyMaterialReceiptResource;
use App\ClubUnio\Resources\LegacyTrainingPlanResource;
use App\ClubUnio\Resources\MaterialReceiptResource;
use App\ClubUnio\Resources\MemberChangeRequestResource;
use App\ClubUnio\Resources\MemberDocumentResource;
use App\ClubUnio\Resources\MemberReportResource;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Resources\MemberStatusResource;
use App\ClubUnio\Resources\MemberTypeResource;
use App\ClubUnio\Resources\MessageTemplateResource;
use App\ClubUnio\Resources\ObjectChangeResource;
use App\ClubUnio\Resources\OpenInvoiceResource;
use App\ClubUnio\Resources\PassiveMemberResource;
use App\ClubUnio\Resources\PaymentReportResource;
use App\ClubUnio\Resources\PlayerReportResource;
use App\ClubUnio\Resources\QualificationResource;
use App\ClubUnio\Resources\RefundResource;
use App\ClubUnio\Resources\RegistrationResource;
use App\ClubUnio\Resources\RegistrationStatusResource;
use App\ClubUnio\Resources\SeasonResource;
use App\ClubUnio\Resources\SettingResource;
use App\ClubUnio\Resources\SignageNoticeResource;
use App\ClubUnio\Resources\SpotsRecipientResource;
use App\ClubUnio\Resources\StaffFunctionResource;
use App\ClubUnio\Resources\StaffReportResource;
use App\ClubUnio\Resources\StatusStyleResource;
use App\ClubUnio\Resources\TeamPlayerResource;
use App\ClubUnio\Resources\TeamResource;
use App\ClubUnio\Resources\TeamSeasonResource;
use App\ClubUnio\Resources\TeamStaffResource;
use App\ClubUnio\Resources\TechnicalLeadResource;
use App\ClubUnio\Resources\TenantAccountResource;
use App\ClubUnio\Resources\AccessProfileResource;
use App\ClubUnio\Resources\TrainingOptionResource;
use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Resources\UnassignedMemberResource;
use App\ClubUnio\SessionPanel;
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
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ClubUnioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return SessionPanel::make()->default()->id('clubunio')->path('')->authGuard('clubunio')
            ->login(Login::class)->brandName('ClubUnio')
            ->brandLogo(asset('brand/blue-v1/clubunio-logo-horizontal.svg'))
            ->darkModeBrandLogo(asset('brand/blue-v1/clubunio-logo-horizontal-white.svg'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('brand/blue-v1/clubunio-symbol.svg'))
            ->renderHook('panels::head.end', fn () => view('clubunio.brand.head', ['withFavicon' => false]))
            ->assets([Css::make('clubunio', public_path('css/svs.css'))->html('<link href="/css/svs.css?v=blue-v1" rel="stylesheet" data-navigate-track />')])
            ->tenant(Tenant::class, slugAttribute: 'slug', ownershipRelationship: 'tenant')
            ->tenantMenu(false)
            ->tenantMiddleware([UseActiveTenant::class], isPersistent: true)
            ->renderHook('panels::sidebar.nav.start', fn () => view('clubunio.tenant-switcher'))
            ->colors(['primary' => array_replace(Color::hex('#164B8A'), [
                400 => Color::convertToOklch('#48A9E8'),
                600 => Color::convertToOklch('#164B8A'),
            ])])
            ->maxContentWidth('full')->sidebarWidth('15rem')
            ->resources([
                AnnouncementResource::class,
                MaterialReceiptResource::class,
                LegacyMaterialReceiptResource::class,
                MemberChangeRequestResource::class,
                ClubEventResource::class,
                ClubFunctionResource::class,
                QualificationResource::class,
                LegacyTrainingPlanResource::class,
                BusKeyboxResource::class,
                CreditNoteResource::class,
                CustomerCreditResource::class,
                RefundResource::class,
                FinancialAdjustmentStatusResource::class,
                InvoiceCategoryResource::class,
                BexioCatalogEntryResource::class,
                StatusStyleResource::class,
                CompetitionResource::class,
                FootballClubResource::class,
                SignageNoticeResource::class,
                FieldAvailabilityResource::class,
                FacilityStatusResource::class,
                MemberReportResource::class,
                UnassignedMemberResource::class,
                PassiveMemberResource::class,
                OpenInvoiceResource::class,
                PlayerReportResource::class,
                StaffReportResource::class,
                FamilyReportResource::class,
                BankAccountReportResource::class,
                KeyReportResource::class,
                SpotsRecipientResource::class,
                PaymentReportResource::class,
                ContributionPriceResource::class,
                ContributionRuleResource::class,
                TrainingSlotResource::class,
                TrainingOptionResource::class,
                AccountMemberLinkResource::class,
                TenantAccountResource::class,
                AccessProfileResource::class,
                MessageTemplateResource::class,
                InvoiceResource::class, ExpenseResource::class,
                InvoiceWorkflowStatusResource::class,
                InvoiceStatusResource::class,
                InvoiceLineStatusResource::class,
                BankResource::class, ExpenseTypeResource::class,
                ExpenseRoundResource::class, ExpenseStatusResource::class, GameTypeResource::class,
                ObjectChangeResource::class,
                TeamPlayerResource::class, TeamStaffResource::class,
                TechnicalLeadResource::class, StaffFunctionResource::class,
                CountryResource::class, GenderResource::class,
                BillingRecipientResource::class, DocumentTypeResource::class,
                FamilyResource::class, FamilyMemberResource::class,
                MemberDocumentResource::class,
                MemberResource::class, SettingResource::class,
                TeamResource::class, TeamSeasonResource::class,
                SeasonResource::class, RegistrationResource::class,
                MemberStatusResource::class, MemberTypeResource::class,
                RegistrationStatusResource::class,
            ])
            ->pages([Dashboard::class, SettingsOverview::class, SignagePreview::class, SfvCompetitions::class, ContributionPreview::class, SeasonPlanning::class, TeamPlanning::class, BusReservations::class, BexioReconciliation::class, BexioDispatchTrials::class, BexioSandbox::class, Synchronisation::class])->widgets([ClubOverview::class, AccountWidget::class,
                MembersByStatus::class, ExpensesByStatus::class, MembersByAge::class,
                ExpensesBySeason::class, NewMembersMonthly::class,
                InvoicesByStatus::class, InvoicesBySeason::class,
                OpenInvoicesTable::class, PendingExpensesTable::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])->authMiddleware([Authenticate::class]);
    }
}
