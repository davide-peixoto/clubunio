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
        'Mitglieder' => ['MemberResource', 'RegistrationResource', 'MemberChangeRequestResource', 'FamilyResource', 'MemberDocumentResource', 'DocumentSeries', 'FamilyMemberResource'],
        'Sport' => ['TeamPlanning', 'SponsorRuns', 'SeasonResource', 'SfvCompetitions', 'CompetitionResource'],
        'Finanzen' => ['InvoiceResource', 'ExpenseResource', 'BexioReconciliation'],
        'Anlagen & Kommunikation' => ['FieldAvailabilityResource', 'BusReservations', 'SignagePreview', 'SignageNoticeResource', 'AnnouncementResource', 'ClubEventResource', 'MaterialReceiptResource', 'LegacyMaterialReceiptResource'],
        'Auswertungen' => ['MemberReportResource', 'UnassignedMemberResource', 'PassiveMemberResource', 'PlayerReportResource', 'StaffReportResource', 'FamilyReportResource', 'OpenInvoiceResource', 'PaymentReportResource', 'BankAccountReportResource', 'KeyReportResource', 'SpotsRecipientResource'],
    ];

    private const SEASON_CHILDREN = ['SeasonPlanning', 'TeamSeasonResource', 'TeamPlayerResource',
        'TeamStaffResource', 'TechnicalLeadResource', 'TrainingSlotResource'];

    private const INVOICE_CHILDREN = ['ContributionPreview', 'CreditNoteResource', 'CustomerCreditResource', 'RefundResource'];

    private const SETTINGS = [
        'Mitglieder & Verein' => ['MemberStatusResource', 'MemberTypeResource', 'RegistrationStatusResource', 'CountryResource', 'GenderResource', 'BillingRecipientResource', 'DocumentTypeResource', 'ClubFunctionResource', 'QualificationResource'],
        'Sport & Anlagen' => ['TeamResource', 'StaffFunctionResource', 'GameTypeResource', 'TrainingOptionResource', 'FootballClubResource', 'FacilityStatusResource', 'BusKeyboxResource'],
        'Finanzen' => ['ContributionPriceResource', 'ContributionRuleResource', 'BankResource', 'ExpenseTypeResource', 'ExpenseRoundResource', 'ExpenseStatusResource', 'InvoiceCategoryResource', 'InvoiceStatusResource', 'InvoiceLineStatusResource', 'InvoiceWorkflowStatusResource', 'FinancialAdjustmentStatusResource', 'BexioCatalogEntryResource', 'BexioDispatchTrials'],
        'Zugänge & System' => ['TenantAccountResource', 'AccessProfileResource', 'AccountMemberLinkResource', 'ApiIntegrations', 'SettingResource', 'MessageTemplateResource', 'LookupTranslationResource', 'StatusStyleResource', 'Synchronisation', 'ObjectChangeResource'],
        'Datenübernahme' => ['LegacyTrainingPlanResource'],
    ];

    /** Reorganize authorized links and add the separately authorized training workspace. */
    public static function partition(Panel $panel, array $groups): array
    {
        $classes = [];
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $classes[$class::getNavigationUrl()] = class_basename($class);
        }
        $main = $settings = $season = $invoices = [];
        foreach ($groups as $group) {
            foreach ($group->getItems() as $item) {
                $item = clone $item;
                $name = $classes[$item->getUrl()] ?? null;
                if (in_array($name, self::SEASON_CHILDREN, true)) {
                    $season[array_search($name, self::SEASON_CHILDREN, true)] = $item;

                    continue;
                }
                if (in_array($name, self::INVOICE_CHILDREN, true)) {
                    $invoices[array_search($name, self::INVOICE_CHILDREN, true)] = $item;

                    continue;
                }
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
        if ($season) {
            ksort($season);
            $position = array_search('SeasonResource', self::PRIMARY['Sport'], true);
            // A structural parent never grants access to the season resource itself.
            $parent = $main['Sport'][$position] ?? NavigationItem::make('Saisons')->icon('heroicon-o-calendar-days');
            $main['Sport'][$position] = $parent->childItems(array_values($season))
                ->extraAttributes(['data-cu-submenu' => 'seasons']);
        }
        if ($invoices) {
            ksort($invoices);
            $parent = $main['Finanzen'][0] ?? NavigationItem::make('Rechnungen')->icon('heroicon-o-document-text');
            $main['Finanzen'][0] = $parent->childItems(array_values($invoices))
                ->extraAttributes(['data-cu-submenu' => 'invoices']);
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
            $active = collect($items)->contains(fn ($item) => $item->isActive() || $item->isChildItemsActive());
            if ($label === 'Einstellungen') {
                $active = $active || collect($settings)->flatten(1)->contains(fn ($item) => $item->isActive());
                foreach ($items as $item) {
                    $item->isActiveWhen(fn () => $active);
                }
            }
            $navigation[] = NavigationGroup::make(in_array($label, ['', 'Einstellungen'], true) ? null : __($label))->items($items)
                ->collapsed($label !== '' && $label !== 'Einstellungen' && ! $active)
                ->collapsible($label !== '' && $label !== 'Einstellungen');
        }
        $orderedSettings = [];
        foreach ([...array_keys(self::SETTINGS), 'Weitere Einstellungen'] as $label) {
            if (! empty($settings[$label])) {
                usort($settings[$label], fn ($a, $b) => strnatcasecmp($a->getLabel(), $b->getLabel()));
                $orderedSettings[__($label)] = $settings[$label];
            }
        }

        $translate = function ($item) use (&$translate) {
            $item->label(__($item->getLabel()));
            foreach ($item->getChildItems() as $child) { $translate($child); }
        };
        foreach ($navigation as $group) {
            foreach ($group->getItems() as $item) { $translate($item); }
        }
        foreach ($orderedSettings as $items) {
            foreach ($items as $item) { $translate($item); }
        }
        return ['navigation' => $navigation, 'settings' => $orderedSettings];
    }
}
