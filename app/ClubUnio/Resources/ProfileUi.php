<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ModulePermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;

/** Additional UI restrictions, never a replacement for policies or write services. */
final class ProfileUi
{
    /** Additional restriction for portal UI which already has ownership and legacy-role guards. */
    public static function profileAllows(\App\ClubUnio\Models\Account $actor, \App\ClubUnio\Models\Tenant $tenant, string $module, string $function): bool
    {
        $permissions = app(ModulePermissionService::class);

        return ! $permissions->isProfile($actor, $tenant) || $permissions->allows($actor, $tenant, $module, $function);
    }

    private static function actor(): ?\App\ClubUnio\Models\Account
    {
        $actor = Filament::auth()->user();
        if ($actor && app(\App\ClubUnio\Services\BackofficeReadContext::class)->tenantsFor($actor) === null) {
            return $actor->fresh();
        }

        return $actor;
    }

    public static function allows(string $module, string $function = 'view'): bool
    {
        $actor = self::actor();
        $tenant = Filament::getTenant();

        return $actor && $tenant && ($actor->hasUnrestrictedBackofficeAccess($tenant)
            || app(ModulePermissionService::class)->allows($actor, $tenant, $module, $function));
    }

    public static function model(object|string $model, string $function = 'view'): bool
    {
        $actor = self::actor();
        $tenant = Filament::getTenant();

        return $actor && $tenant && ($actor->hasUnrestrictedBackofficeAccess($tenant)
            || app(ModulePermissionService::class)->allowsModel($actor, $tenant, $model, $function));
    }

    public static function resource(string $resource, string $function = 'view'): bool
    {
        if (in_array(class_basename($resource), ['MemberReportResource', 'UnassignedMemberResource', 'PassiveMemberResource',
            'PlayerReportResource', 'StaffReportResource', 'FamilyReportResource', 'OpenInvoiceResource',
            'PaymentReportResource', 'BankAccountReportResource', 'KeyReportResource', 'SpotsRecipientResource'], true)) {
            return self::allows('reports', $function);
        }

        return self::model($resource::getModel(), $function);
    }

    public static function actionFunction(string $name): ?string
    {
        return match ($name) {
            'view', 'member', 'family', 'details', 'inspect', 'compare', 'dossierHistory', 'history', 'positions', 'viewCustomerCredit',
            'loadView', 'saveView', 'manageView', 'deleteView', 'calculate', 'seasonRun', 'publicDisplay', 'publicForm',
            'duplicates', 'messages', 'notificationPreview', 'openFilters', 'applyFilters', 'resetFilters',
            'removeAllFilters', 'openColumnManager', 'resetColumnManager', 'applyTableColumnManager' => 'view',
            'create', 'addItem', 'addAssignment', 'addLine', 'newLine', 'newBank', 'addNote', 'addGuest',
            'newAnnouncement', 'newRegistration', 'newReceipt', 'newExpense', 'createInvoice', 'createExpense', 'createFinance',
            'createCredit', 'createRefund', 'newVariant', 'customerCredit' => 'create',
            'edit', 'editItem', 'editAssignment', 'editLine', 'editBank', 'changeNote', 'editGuest', 'editDraft',
            'editReceipt', 'editHeader', 'editFinance', 'correctReference', 'manageDocument', 'applicant', 'change', 'bexioBillingRecipient' => 'update',
            'copyItem', 'copyAssignment', 'copyLine', 'copyRow', 'duplicate' => 'copy',
            'archive', 'withdraw', 'removeLine', 'removeDocument', 'deactivateItem', 'deactivateItems', 'deactivateAssignment',
            'deactivateAssignments', 'deactivateRow', 'deactivateRecord' => 'deactivate',
            'restore', 'restoreLine', 'restoreItem', 'restoreItems', 'restoreAssignment', 'restoreAssignments',
            'restoreRecord', 'restoreRevision' => 'restore',
            'pdf', 'receiptPdf', 'signedPdf', 'originalPdf', 'document', 'documents', 'previewDocument', 'downloadDocument' => 'download',
            'upload', 'uploadDocument' => 'upload',
            'export', 'exportContacts' => 'export',
            'approve', 'reject', 'review', 'release', 'releaseFinance', 'confirm', 'transition' => 'approve',
            'publish', 'sendBexio', 'testMail', 'issueReceipt', 'bexio_issue' => 'send',
            'cancel', 'cancelFinance', 'cancelReceipt', 'bexio_cancel' => 'cancel',
            'sync', 'stage', 'import', 'sfv', 'bexioStatus', 'allocate' => 'sync',
            'transferSeason' => 'transfer',
            default => null,
        };
    }

    public static function actionAllowed(Action $action): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        if (! $actor || ! $tenant || ! app(ModulePermissionService::class)->isProfile($actor, $tenant)) {
            return true;
        }
        $component = $action->getLivewire();
        if (! $component || ! str_starts_with($component::class, 'App\\ClubUnio\\')) {
            return true;
        }
        if (($parent = $action->getParentAction()) && in_array($action->getName(), ['submit', 'cancel', 'createAnother'], true)) {
            return $action->getName() === 'cancel' || self::actionAllowed($parent);
        }
        // Repeater controls only change the pending form state; the owning action remains guarded.
        if ($action->getSchemaComponent() instanceof \Filament\Forms\Components\Repeater
            && in_array($action->getName(), ['add', 'addBetween', 'delete', 'clone', 'moveUp', 'moveDown',
                'reorder', 'collapse', 'expand', 'collapseAll', 'expandAll'], true)) {
            return true;
        }
        $function = self::actionFunction($action->getName());
        if ($function === null) {
            return false;
        }
        if (in_array($action->getName(), ['createCredit', 'createRefund', 'customerCredit', 'viewCustomerCredit'], true)) {
            return self::allows('credits', $function);
        }
        if ($action->getName() === 'bexioBillingRecipient') {
            return self::allows('bexio', 'update');
        }
        if ($action->getName() === 'transferSeason') {
            return self::allows('teams', 'transfer') && self::allows('seasons', 'transfer');
        }
        if ($action->getName() === 'transition') {
            $record = $action->getRecord();
            if (! $record && method_exists($component, 'getRecord')) {
                $record = $component->getRecord();
            }

            return $record instanceof \App\ClubUnio\Models\Expense
                && app(\App\ClubUnio\Services\ExpenseService::class)->targets($actor, $tenant, $record) !== [];
        }
        if ($component instanceof \Filament\Resources\RelationManagers\RelationManager) {
            return self::model($component->getRelationship()->getRelated(), $function)
                && $actor->can('view', $component->getOwnerRecord());
        }
        if (method_exists($component, 'getResource')) {
            return self::resource($component::getResource(), $function);
        }
        $module = match (class_basename($component)) {
            'ContributionPreview' => 'contributions', 'SfvCompetitions' => 'competitions',
            'SignagePreview' => 'signage', 'BexioReconciliation' => 'bexio',
            default => null,
        };

        return $module !== null && self::allows($module, $function);
    }
}
