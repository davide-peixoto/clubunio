<?php

namespace App\ClubUnio\Pages;

trait LocalizedTitle
{
    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $title = parent::getTitle();
        return is_string($title) ? __($title) : $title;
    }
}
