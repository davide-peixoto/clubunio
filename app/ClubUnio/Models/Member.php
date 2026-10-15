<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    use UsesClubUnioSchema;

    public function materialReceipts()
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    public function legacyMaterialReceipts()
    {
        return $this->hasMany(LegacyMaterialReceipt::class);
    }

    protected $connection = 'clubunio';

    protected $guarded = ['*'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function status()
    {
        return $this->belongsTo(MemberStatus::class, 'status_id');
    }

    public function type()
    {
        return $this->belongsTo(MemberType::class, 'type_id');
    }

    protected $hidden = ['ahv_encrypted', 'legacy_photo', 'legacy_photo2'];

    protected $casts = ['birth_date' => 'date', 'exit_date' => 'date', 'archived_at' => 'datetime', 'honorary' => 'boolean',
        'free_membership' => 'boolean', 'supporter' => 'boolean', 'sponsor' => 'boolean', 'spots' => 'boolean'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function nationality()
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function gender()
    {
        return $this->belongsTo(Gender::class);
    }

    public function billingRecipient()
    {
        return $this->belongsTo(BillingRecipient::class);
    }

    public function familyMemberships()
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(MemberBankAccount::class, 'member_id');
    }

    public function notes()
    {
        return $this->hasMany(MemberNote::class, 'member_id');
    }

    public function functions()
    {
        return $this->hasMany(MemberFunction::class, 'member_id');
    }

    public function keys()
    {
        return $this->hasMany(MemberKey::class, 'member_id');
    }

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'member_id');
    }

    public function eventGuests()
    {
        return $this->hasMany(EventGuest::class, 'member_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'member_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'member_id');
    }

    public function teamPlayers()
    {
        return $this->hasMany(TeamPlayer::class, 'member_id');
    }

    public function teamStaff()
    {
        return $this->hasMany(TeamStaff::class, 'member_id');
    }

    public function technicalLeads()
    {
        return $this->hasMany(TechnicalLead::class, 'member_id');
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class, 'member_id');
    }

    public function documents()
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->company ?: $this->first_name.' '.$this->last_name).' (#'.($this->member_number ?? $this->legacy_id ?? $this->id).')';
    }
}
