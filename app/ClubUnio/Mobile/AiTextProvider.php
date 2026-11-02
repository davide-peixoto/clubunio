<?php

namespace App\ClubUnio\Mobile;

interface AiTextProvider
{
    /** Stateless text in/text out. No conversation, tool invocation or message delivery. */
    public function transform(string $operation, string $text, string $language): array;
}
