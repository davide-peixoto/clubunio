<?php

namespace App\ClubUnio\Services\Storage;

use Illuminate\Support\Facades\DB;

class DriveObjectIndex
{
    private function entry(object $profile, string $key): array
    {
        return ['profile_id' => $profile->id, 'object_key' => hash('sha256', $key)];
    }

    private function table()
    {
        return DB::connection('clubunio')->table('clubunio.tenant_storage_objects');
    }

    public function id(object $profile, string $key): ?string
    {
        return $this->table()->where($this->entry($profile, $key))->value('remote_id');
    }

    public function remember(object $profile, string $key, mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_!.-]{1,255}$/D', $id) || in_array($id, ['.', '..'], true)) {
            throw ProviderBytes::unavailable();
        }
        $this->table()->insertOrIgnore($this->entry($profile, $key) + ['remote_id' => $id]);

        return $this->id($profile, $key) ?? throw ProviderBytes::unavailable();
    }

    public function filename(string $key): string
    {
        return hash('sha256', $key).'.'.pathinfo($key, PATHINFO_EXTENSION);
    }
}
