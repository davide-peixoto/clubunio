<?php

namespace App\ClubUnio\Models;

use Illuminate\Support\Facades\Crypt;

class MemberBankAccount extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    protected $hidden = ['iban_encrypted'];

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function getMaskedIbanAttribute(): string
    {
        return '•••• '.substr(Crypt::decryptString($this->iban_encrypted), -4);
    }
}
