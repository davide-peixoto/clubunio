<?php

namespace App\ClubUnio;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Facades\Filament;
use App\ClubUnio\Resources\ProfileUi;
use Filament\Panel;

class NavigationStructure
{
    private const PRIMARY = [
        'Mitglieder' => ['MemberResource', 'RegistrationResource', 'MemberChangeRequestResource', 'FamilyResource', 'MemberDocumentResource', 'FamilyMemberResource'],
        'Sport' => ['TeamPlanning', 'SeasonResource', 'TeamSeasonResource', 'TeamPlayerResource', 'TeamStaffResource', 'TechnicalLeadResource', 'TrainingSlotResource', 'SfvCompetitions', 'CompetitionResource', 'LegacyTrainingPlanResource'],
        'Finanzen' => ['InvoiceResource', 'ExpenseResource', 'ContributionPreview', 'CustomerCreditResource', 'CreditNoteResource', 'RefundResource', 'BexioReconciliation'],
        'Anlagen & Kommunikation' => ['FieldAvailabilityResource', 'BusReservations', 'SignagePreview', 'SignageNoticeResource', 'AnnouncementResource', 'ClubEventResource', 'MaterialReceiptResource', 'LegacyMaterialReceiptResource'],
        'Auswertungen' => ['MemberReportResource', 'UnassignedMemberResource', 'PassiveMemberResource', 'PlayerReportResource', 'StaffReportResource', 'FamilyReportResource', 'OpenInvoiceResource', 'PaymentReportResource', 'BankAccountReportResource', 'KeyReportResource', 'SpotsRecipientResource'],
    ];

    private const SETTINGS = [
        'Mitglieder & Verein' => ['MemberStatusResource', 'MemberTypeResource', 'RegistrationStatusResource', 'CountryResource', 'GenderResource', 'BillingRecipientResource', 'DocumentTypeResource', 'ClubFunctionResource', 'QualificationResource'],
        'Sport & Anlagen' => ['TeamResource', 'StaffFunctionResource', 'GameTypeResource', 'TrainingOptionResource', 'FootballClubResource', 'FacilityStatusResource', 'BusKeyboxResource'],
        'Finanzen' => ['ContributionPriceResource', 'ContributionRuleResource', 'BankResource', 'ExpenseTypeResource', 'ExpenseRoundResource', 'ExpenseStatusResource', 'InvoiceCategoryResource', 'InvoiceStatusResource', 'InvoiceLineStatusResource', 'InvoiceWorkflowStatusResource', 'FinancialAdjustmentStatusResource', 'BexioCatalogEntryResource', 'BexioDispatchTrials'],
        'Zugänge & System' => ['TenantAccountResource', 'AccessProfileResource', 'AccountMemberLinkResource', 'SettingResource', 'MessageTemplateResource', 'StatusStyleResource', 'Synchronisation', 'ObjectChangeResource'],
    ];

    /** Reorganize authorized links and add the separately authorized training workspace. */
    public static function partition(Panel $panel, array $groups): array
    {
        $classes = [];
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $classes[$class::getNavigationUrl()] = class_basename($class);
        }
        $main = $settings = [];
        foreach ($groups as $group) {
            foreach ($group->getItems() as $item) {
                $name = $classes[$item->getUrl()] ?? null;
                if ($name === 'Dashboard') {
                    $main[''][] = $item;

                    continue;
                }
                if ($name === 'SettingsOverview') {
                    $main['Einstellungen'][] = $item;

                    continue;
                }
                $placed = false;
                foreach (self::PRIMARY as $label => $members) {
                    if (in_array($name, $members, true)) {
                        $main[$label][array_search($name, $members, true)] = $item;
                        $placed = true;
                        break;
                    }
                }
                if ($placed) {
                    continue;
                }
                $category = 'Weitere Einstellungen';
                foreach (self::SETTINGS as $label => $members) {
                    if (in_array($name, $members, true)) {
                        $category = $label;
                        break;
                    }
                }
                if ($name === 'SettingResource') {
                    $item->label('Parameter & Integrationen');
                }
                $settings[$category][] = $item;
            }
        }
        if (ProfileUi::allows('training')) {
            $main['Sport'][] = NavigationItem::make('Trainingspläne')
                ->icon('heroicon-o-academic-cap')
                ->url(route('clubunio.training.index', Filament::getTenant()->slug))
                ->isActiveWhen(fn () => request()->routeIs('clubunio.training.*', 'clubunio.workspace.training.*'));
        }
        $navigation = [];
        foreach (['', ...array_keys(self::PRIMARY), 'Einstellungen'] as $label) {
            if (empty($main[$label])) {
                continue;
            }
            ksort($main[$label]);
            $items = array_values($main[$label]);
            $active = collect($items)->contains(fn ($item) => $item->isActive());
            if ($label === 'Einstellungen') {
                $active = $active || collect($settings)->flatten(1)->contains(fn ($item) => $item->isActive());
                foreach ($items as $item) {
                    $item->isActiveWhen(fn () => $active);
                }
            }
            $navigation[] = NavigationGroup::make(in_array($label, ['', 'Einstellungen'], true) ? null : $label)->items($items)
                ->collapsed($label !== '' && $label !== 'Einstellungen' && ! $active)
                ->collapsible($label !== '' && $label !== 'Einstellungen');
        }
        $orderedSettings = [];
        foreach ([...array_keys(self::SETTINGS), 'Weitere Einstellungen'] as $label) {
            if (! empty($settings[$label])) {
                usort($settings[$label], fn ($a, $b) => strnatcasecmp($a->getLabel(), $b->getLabel()));
                $orderedSettings[$label] = $settings[$label];
            }
        }

        return ['navigation' => $navigation, 'settings' => $orderedSettings];
    }
}
