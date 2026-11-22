<?php

namespace App\ClubUnio\Models;

class MemberStatus extends CatalogModel
{
    protected $table = 'member_statuses';

    protected $casts = ['active' => 'boolean', 'member_card_eligible' => 'boolean'];
}
