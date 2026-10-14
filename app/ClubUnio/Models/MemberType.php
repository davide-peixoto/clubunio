<?php

namespace App\ClubUnio\Models;

class MemberType extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'team_required' => 'boolean', 'passive_membership' => 'boolean'];
}
