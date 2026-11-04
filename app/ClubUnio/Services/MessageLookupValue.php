<?php

namespace App\ClubUnio\Services;

/** Explicit lookup identity; free-text placeholder values must never be translated. */
final readonly class MessageLookupValue
{
    public function __construct(public string $table, public array $row) {}

    public function resolve(string $locale, int $tenantId): string
    {
        $language = explode('-', $locale)[0];

        return (string) app(LookupTranslations::class)->label($this->table, $this->row, $language, $tenantId);
    }
}
