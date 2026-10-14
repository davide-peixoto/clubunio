<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use UsesClubUnioSchema;

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function financialAdjustmentStatuses()
    {
        return $this->hasMany(FinancialAdjustmentStatus::class);
    }

    public function bexioCatalogEntries()
    {
        return $this->hasMany(BexioCatalogEntry::class);
    }

    public function invoiceCategories()
    {
        return $this->hasMany(InvoiceCategory::class);
    }

    public function statusStyles()
    {
        return $this->hasMany(StatusStyle::class);
    }

    public function competitions()
    {
        return $this->hasMany(Competition::class);
    }

    public function signageNotices()
    {
        return $this->hasMany(SignageNotice::class);
    }

    public function fieldAvailabilities()
    {
        return $this->hasMany(FieldAvailability::class);
    }

    public function facilityStatuses()
    {
        return $this->hasMany(FacilityStatus::class);
    }

    public function accountMemberLinks()
    {
        return $this->hasMany(AccountMemberLink::class);
    }

    protected $connection = 'clubunio';

    protected $guarded = ['id'];

    public function messageTemplates()
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'clubora.tenant_accounts')->withPivot('role');
    }

    public function members()
    {
        return $this->hasMany(Member::class);
    }

    public function memberKeys()
    {
        return $this->hasMany(MemberKey::class);
    }

    public function spotsRecipients()
    {
        return $this->hasMany(SpotsRecipient::class);
    }

    public function settings()
    {
        return $this->hasMany(Setting::class);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function seasons()
    {
        return $this->hasMany(Season::class);
    }

    public function teamSeasons()
    {
        return $this->hasMany(TeamSeason::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function memberStatuses()
    {
        return $this->hasMany(MemberStatus::class);
    }

    public function memberTypes()
    {
        return $this->hasMany(MemberType::class);
    }

    public function registrationStatuses()
    {
        return $this->hasMany(RegistrationStatus::class);
    }

    public function countries()
    {
        return $this->hasMany(Country::class);
    }

    public function genders()
    {
        return $this->hasMany(Gender::class);
    }

    public function billingRecipients()
    {
        return $this->hasMany(BillingRecipient::class);
    }

    public function documentTypes()
    {
        return $this->hasMany(DocumentType::class);
    }

    public function families()
    {
        return $this->hasMany(Family::class);
    }

    public function familyMembers()
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function memberBankAccounts()
    {
        return $this->hasMany(MemberBankAccount::class);
    }

    public function memberDocuments()
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function teamPlayers()
    {
        return $this->hasMany(TeamPlayer::class);
    }

    public function teamStaff()
    {
        return $this->hasMany(TeamStaff::class);
    }

    public function technicalLeads()
    {
        return $this->hasMany(TechnicalLead::class);
    }

    public function staffFunctions()
    {
        return $this->hasMany(StaffFunction::class);
    }

    public function objectChanges()
    {
        return $this->hasMany(ObjectChange::class);
    }

    public function banks()
    {
        return $this->hasMany(Bank::class);
    }

    public function contributionPrices()
    {
        return $this->hasMany(ContributionPrice::class);
    }

    public function contributionRules()
    {
        return $this->hasMany(ContributionRule::class);
    }

    public function trainingSlots()
    {
        return $this->hasMany(TrainingSlot::class);
    }

    public function trainingOptions()
    {
        return $this->hasMany(TrainingOption::class);
    }

    public function expenseTypes()
    {
        return $this->hasMany(ExpenseType::class);
    }

    public function expenseRounds()
    {
        return $this->hasMany(ExpenseRound::class);
    }

    public function expenseStatuses()
    {
        return $this->hasMany(ExpenseStatus::class);
    }

    public function gameTypes()
    {
        return $this->hasMany(GameType::class);
    }

    public function invoiceWorkflowStatuses()
    {
        return $this->hasMany(InvoiceWorkflowStatus::class);
    }

    public function invoiceStatuses()
    {
        return $this->hasMany(InvoiceStatus::class);
    }

    public function invoiceLineStatuses()
    {
        return $this->hasMany(InvoiceLineStatus::class);
    }

    public function tenantAccounts()
    {
        return $this->hasMany(TenantAccount::class);
    }

    public function footballClubs()
    {
        return $this->hasMany(FootballClub::class);
    }
}
