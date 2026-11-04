<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Member;

class MembersByStatus extends StatusDistribution
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('members');
    }

    public function getHeading(): string
    {
        return __('Mitglieder nach Status');
    }

    protected function modelClass(): string
    {
        return Member::class;
    }
}
