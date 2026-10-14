<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Member;

class MembersByStatus extends StatusDistribution
{
    protected ?string $heading = 'Mitglieder nach Status';

    protected function modelClass(): string
    {
        return Member::class;
    }
}
