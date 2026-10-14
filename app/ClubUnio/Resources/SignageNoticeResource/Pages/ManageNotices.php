<?php

namespace App\ClubUnio\Resources\SignageNoticeResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\SignageNoticeResource;
use Filament\Resources\Pages\ManageRecords;

class ManageNotices extends ManageRecords
{
    use HasSavedViews;

    protected static string $resource = SignageNoticeResource::class;

    protected function savedFilterRules(): array
    {
        return ['upcoming' => ['isActive' => 'required|boolean']];
    }
}
