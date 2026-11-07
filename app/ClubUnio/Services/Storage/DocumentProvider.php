<?php

namespace App\ClubUnio\Services\Storage;

interface DocumentProvider
{
    public function check(object $profile, array $credentials): void;

    public function read(object $profile, array $credentials, string $key): string;

    public function put(object $profile, array $credentials, string $key, string $bytes, string $mime): void;
}
