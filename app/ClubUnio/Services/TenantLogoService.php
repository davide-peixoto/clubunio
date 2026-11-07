<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Setting;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TenantLogoService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function encode(UploadedFile $file): string
    {
        Validator::make(['logo_file' => $file], ['logo_file' => 'required|file|max:5120|mimetypes:image/png,image/jpeg,image/webp'])->validate();
        $bytes = $file->get();
        $size = @getimagesizefromstring($bytes);
        if (! function_exists('imagecreatefromstring') || ! $size || min($size[0], $size[1]) < 1
            || max($size[0], $size[1]) > 2048 || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || ! ($image = @imagecreatefromstring($bytes))) {
            throw ValidationException::withMessages(['logo_file' => __('Use a valid PNG, JPEG or WebP image up to 2048 × 2048 pixels.')]);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            $ok = imagepng($image);
        } finally {
            $png = ob_get_clean();
        }
        if (! $ok || $png === '' || strlen($png) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['logo_file' => __('The converted logo exceeds 5 MiB.')]);
        }

        return $png;
    }

    public function store(Tenant $tenant, string $png): string
    {
        $version = hash('sha256', $png);
        abort_unless(Storage::disk('local')->put($this->path($tenant->id, $version), $png, 'private'), 503);

        return $this->url($tenant->id, $version);
    }

    private function path(int $id, string $version): string
    {
        return 'tenant-logos/'.$id.'/'.$version.'.png';
    }

    private function url(int $id, string $version): string
    {
        return '/club-logo/'.$id.'/'.$version.'.png';
    }

    public function response(int $id, string $version)
    {
        $setting = Setting::where('tenant_id', $id)->where('area', 'signage')->where('enabled', true)
            ->where('key', SignageBrandService::KEYS['logo'])->value('encrypted_value');
        try {
            $current = $setting ? Crypt::decryptString($setting) : '';
        } catch (\Throwable) {
            $current = '';
        }
        abort_unless(hash_equals($this->url($id, $version), $current), 404);
        $disk = Storage::disk('local');
        $path = $this->path($id, $version);
        abort_unless($disk->exists($path) && $disk->size($path) <= self::MAX_BYTES, 404);
        $png = $disk->get($path);
        abort_unless(is_string($png) && hash_equals($version, hash('sha256', $png)), 404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
