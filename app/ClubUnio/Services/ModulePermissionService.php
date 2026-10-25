<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Fresh authorization for a single operation; never a replacement for record ownership checks. */
final class ModulePermissionService
{
    private const MODELS = [
        'members' => ['Member', 'MemberNote', 'MemberFunction', 'MemberKey', 'Sponsorship', 'MemberBankAccount'],
        'registrations' => ['Registration'], 'member_changes' => ['MemberChangeRequest'],
        'documents' => ['MemberDocument', 'MaterialReceipt', 'LegacyMaterialReceipt', 'DocumentSeries', 'DocumentSeriesTemplate', 'DocumentSeriesRecipient'],
        'sponsor_runs' => ['SponsorRunEvent', 'SponsorRunParticipant', 'SponsorRunPledge'],
        'families' => ['Family', 'FamilyMember'], 'seasons' => ['Season'],
        'teams' => ['Team', 'TeamSeason', 'TeamPlayer', 'TeamStaff', 'TechnicalLead'],
        'training' => ['LegacyTrainingPlan'], 'training_slots' => ['TrainingSlot'],
        'competitions' => ['Competition'], 'invoices' => ['Invoice', 'InvoiceLine'],
        'expenses' => ['Expense', 'ExpenseLine'], 'contributions' => ['ContributionPrice', 'ContributionRule'],
        'credits' => ['CreditNote', 'CreditNoteLine', 'CustomerCredit', 'CustomerCreditAllocation', 'Refund'],
        'bexio' => ['BexioBalance', 'BexioCatalogEntry'], 'bus' => ['Bus'],
        'facilities' => ['FacilityStatus'], 'field_availability' => ['FieldAvailability'], 'signage' => ['SignageNotice'],
        'announcements' => ['Announcement'], 'events' => ['ClubEvent', 'EventGuest'],
        'reports' => ['SpotsRecipient'], 'templates' => ['MessageTemplate', 'MessageTemplateRevision'],
        'catalogs' => ['Bank', 'BillingRecipient', 'ClubFunction', 'Country', 'DocumentType', 'ExpenseRound',
            'ExpenseStatus', 'ExpenseType', 'FinancialAdjustmentStatus', 'FootballClub', 'GameType', 'Gender',
            'InvoiceCategory', 'InvoiceLineStatus', 'InvoiceStatus', 'InvoiceWorkflowStatus', 'MemberChangeStatus',
            'MemberStatus', 'MemberType', 'Qualification', 'RegistrationStatus', 'StaffFunction', 'TrainingOption'],
    ];

    public static function modelModule(object|string $model): ?string
    {
        $class = is_object($model) ? $model::class : $model;
        if (! str_starts_with($class, 'App\\ClubUnio\\Models\\')) {
            return null;
        }
        foreach (self::MODELS as $module => $classes) {
            if (in_array(class_basename($class), $classes, true)) {
                return $module;
            }
        }
        return null;
    }

    public function allowsModel(Account $actor, Tenant $tenant, object|string $model, string $function): bool
    {
        if (is_object($model) && $model->exists && (int) $model->tenant_id !== (int) $tenant->id) {
            return false;
        }
        $module = self::modelModule($model);
        return $module !== null && $this->allows($actor, $tenant, $module, $function);
    }

    public function isProfile(Account $actor, Tenant $tenant): bool
    {
        $reads = app(BackofficeReadContext::class);
        if ($reads->tenantsFor($actor)?->contains(fn ($row) => (int) $row->id === (int) $tenant->id)) {
            return false;
        }
        $key = 'profile:'.$tenant->id;
        if (($cached = $reads->permissionCache($actor, $key)) !== null) {
            return $cached['value'];
        }
        $value = DB::connection('clubunio')->table('clubora.tenant_accounts')
            ->where('account_id', $actor->id)->where('tenant_id', $tenant->id)->where('role', 'profile')->exists();
        $reads->permissionCache($actor, $key, ['value' => $value]);
        return $value;
    }

    public function canEnter(Account $actor, Tenant $tenant): bool
    {
        if ($actor->hasUnrestrictedBackofficeAccess($tenant)) {
            return true;
        }
        if (! $this->isProfile($actor, $tenant)) {
            return false;
        }
        $profile = DB::connection('clubunio')->table('clubora.tenant_accounts as membership')
            ->join('clubora.accounts as account', 'account.id', '=', 'membership.account_id')
            ->join('clubora.account_roles as role', 'role.code', '=', 'membership.role')
            ->join('clubora.access_profiles as profile', function ($join) {
                $join->on('profile.id', '=', 'membership.access_profile_id')->on('profile.tenant_id', '=', 'membership.tenant_id');
            })->where('membership.account_id', $actor->id)->where('membership.tenant_id', $tenant->id)
            ->where('membership.role', 'profile')->where('account.active', true)->where('role.active', true)
            ->where('profile.active', true)->value('profile.permissions');
        return $profile !== null && array_intersect(PermissionCatalog::readOnly(), json_decode($profile, true, flags: JSON_THROW_ON_ERROR)) !== [];
    }
    public function allows(Account $actor, Tenant $tenant, string $module, string $function): bool
    {
        // Existing broad facilities grants retain their scope; narrow grants never flow back.
        if ($module === 'field_availability' && $this->allows($actor, $tenant, 'facilities', $function)) {
            return true;
        }
        if (! array_key_exists($module, PermissionCatalog::MODULES)
            || ! array_key_exists($function, PermissionCatalog::functions($module))) {
            return false;
        }
        $reads = app(BackofficeReadContext::class);
        if ($reads->tenantsFor($actor)?->contains(fn ($row) => (int) $row->id === (int) $tenant->id)) {
            return true;
        }
        $cacheKey = 'permissions:'.$tenant->id;
        if (($cached = $reads->permissionCache($actor, $cacheKey)) !== null) {
            return in_array($module.'.view', $cached, true) && in_array($module.'.'.$function, $cached, true);
        }
        $db = DB::connection('clubunio');
        $link = $db->table('clubora.tenant_accounts as membership')
            ->join('clubora.accounts as account', 'account.id', '=', 'membership.account_id')
            ->where('membership.account_id', $actor->id)->where('membership.tenant_id', $tenant->id)
            ->where('account.active', true)->select('membership.*')->first();
        if (! $link) {
            return false;
        }
        if (in_array($link->role, ['admin', 'office'], true)) {
            return true;
        }
        if ($link->role !== 'profile' || ! ($link->access_profile_id ?? null)) {
            return false;
        }
        if (! $db->table('clubora.account_roles')->where('code', 'profile')->where('active', true)->exists()) {
            return false;
        }
        $profile = $db->table('clubora.access_profiles')->where('tenant_id', $tenant->id)
            ->where('id', $link->access_profile_id)->where('active', true)->first();
        if (! $profile) {
            return false;
        }
        $permissions = json_decode($profile->permissions, true, flags: JSON_THROW_ON_ERROR);
        $reads->permissionCache($actor, $cacheKey, $permissions);

        return in_array($module.'.view', $permissions, true) && in_array($module.'.'.$function, $permissions, true);
    }

    public function authorize(Account $actor, Tenant $tenant, string $module, string $function): void
    {
        abort_unless($this->allows($actor, $tenant, $module, $function), 403);
    }
}
