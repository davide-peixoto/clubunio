<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\Storage\DocumentProvider;
use App\ClubUnio\Services\Storage\GoogleDriveDocumentProvider;
use App\ClubUnio\Services\Storage\GraphDocumentProvider;
use App\ClubUnio\Services\Storage\ProviderBytes;
use App\ClubUnio\Services\Storage\S3DocumentProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TenantDocumentStorage
{
    public const PROFILE = '[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}';

    public const PROVIDERS = ['azure', 'managed', 's3', 'google', 'graph'];

    public static function enabled(string $provider): bool
    {
        return $provider === 'azure' || (in_array($provider, self::PROVIDERS, true) && config('clubunio-storage.'.$provider.'_enabled'));
    }

    private function profiles(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.tenant_storage_profiles')->where('tenant_id', $tenant->id);
    }

    public function active(Tenant $tenant): ?object
    {
        return config('clubunio-storage.profiles_enabled') ? $this->profiles($tenant)->where('active', true)
            ->first(['id', 'provider', 'account', 'container']) : null;
    }

    public function destinations(Tenant $tenant): array
    {
        return config('clubunio-storage.profiles_enabled') ? $this->profiles($tenant)->orderByDesc('active')->orderBy('provider')
            ->get(['id', 'provider', 'account', 'container', 'active'])->all() : [];
    }

    public function canUpload(Tenant $tenant): bool
    {
        $profile = $this->active($tenant);

        return $profile ? self::enabled($profile->provider) : $this->legacyTenant($tenant);
    }

    private function legacyTenant(Tenant $tenant): bool
    {
        return $tenant->slug === config('clubunio-integrations.azure.tenant_slug');
    }

    // Caller holds the tenant row lock and has authorized the platform operator.
    public function select(Tenant $tenant, array $input): array
    {
        abort_unless(config('clubunio-storage.profiles_enabled') && DB::connection('clubunio')->transactionLevel() > 0, 403);
        $data = Validator::make($input, [
            'provider' => 'required|in:'.implode(',', self::PROVIDERS),
            'storage_account' => 'nullable|string|max:64',
            'storage_container' => 'nullable|string|max:255',
            'storage_owner' => ['nullable', 'string', 'regex:/^[0-9]{12}$/D', 'prohibited_unless:provider,s3'],
            'storage_key' => 'nullable|string|max:16384|prohibited_if:provider,managed',
            'storage_private_confirmed' => 'accepted_if:provider,graph|prohibited_unless:provider,graph',
            'storage_action' => 'sometimes|in:select,rotate',
        ])->validate();
        $provider = $data['provider'];
        abort_unless(self::enabled($provider), 403);
        $target = $this->target($tenant, $data);
        $existing = $this->profiles($tenant)->where($target)->first(['id', 'encrypted_key']);
        $key = $data['storage_key'] ?? null;
        $rotate = ($data['storage_action'] ?? 'select') === 'rotate';
        if ($rotate && (! $existing || ! $key)) {
            throw ValidationException::withMessages(['storage_key' => __('Enter new credentials for an existing storage destination.')]);
        }
        if ($provider === 'azure' && ((! $existing && ! $key) || ($key && strlen(base64_decode($key, true) ?: '') !== 64))) {
            throw ValidationException::withMessages(['storage_key' => __('Enter a valid Azure storage account key.')]);
        }
        $id = $existing?->id ?? (string) Str::uuid();
        $profile = (object) ($target + ['id' => $id, 'encrypted_key' => $existing?->encrypted_key]);
        try {
            if ($provider === 'azure') {
                app(RegistrationBlobStore::class)->checkAt($key ? ($target + ['key' => $key]) : $this->azure($profile));
            } elseif ($remote = $this->remote($provider)) {
                $credentials = $key === null ? $this->credentials($profile) : $this->decode($provider, $key);
                $remote->check($profile, $credentials);
                $key = $key === null ? null : json_encode($credentials, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['storage_key' => __('Storage connection could not be verified. Check the credentials, destination and private access.')]);
        }
        if ($rotate) {
            $this->profiles($tenant)->where('id', $id)->update(['encrypted_key' => Crypt::encryptString($key)]);

            return ['profile_id' => $id, 'provider' => $provider, 'storage_action' => 'rotate'];
        }
        $this->profiles($tenant)->where('active', true)->update(['active' => false]);
        if ($existing) {
            $this->profiles($tenant)->where('id', $id)->update(['active' => true] + ($key ? ['encrypted_key' => Crypt::encryptString($key)] : []));
        } else {
            $inserted = $this->profiles($tenant)->insertOrIgnore($target + ['tenant_id' => $tenant->id, 'id' => $id, 'active' => true,
                'encrypted_key' => $key ? Crypt::encryptString($key) : null]);
            if (! $inserted) {
                throw ValidationException::withMessages(['storage_container' => __('This storage destination is already assigned.')]);
            }
        }

        return ['profile_id' => $id, 'provider' => $provider];
    }

    private function target(Tenant $tenant, array $data): array
    {
        $provider = $data['provider'];
        $account = $data['storage_account'] ?? '';
        $container = $data['storage_container'] ?? '';
        $valid = match ($provider) {
            'azure' => preg_match('/^[a-z0-9]{3,24}$/D', $account)
                && preg_match('/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){1,61}[a-z0-9]$/D', $container)
                && ($this->legacyTenant($tenant) || $account !== config('clubunio-integrations.azure.account')),
            's3' => preg_match('/^[a-z]{2}(?:-[a-z0-9]+){1,3}-[1-9][0-9]?$/D', $account) && strlen($account) <= 24
                && isset($data['storage_owner']) && preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $container),
            'google' => $account === '' && preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $container),
            'graph' => preg_match('/^'.self::PROFILE.'$/D', $account) && preg_match('/^[A-Za-z0-9_!-]{1,255}$/D', $container),
            'managed' => $account === '' && $container === '',
        };
        if (! $valid) {
            throw ValidationException::withMessages(['storage_container' => __('Enter a valid private storage destination.')]);
        }

        return ['provider' => $provider, 'account' => $provider === 's3' ? $account.':'.$data['storage_owner'] : $account, 'container' => $container];
    }

    private function remote(string $provider): ?DocumentProvider
    {
        return match ($provider) {
            's3' => app(S3DocumentProvider::class),
            'google' => app(GoogleDriveDocumentProvider::class),
            'graph' => app(GraphDocumentProvider::class),
            default => null,
        };
    }

    private function credentials(object $profile): array
    {
        return $this->decode($profile->provider, Crypt::decryptString($profile->encrypted_key));
    }

    private function decode(string $provider, string $key): array
    {
        $data = json_decode($key, true, 32, JSON_THROW_ON_ERROR);
        $fields = match ($provider) {
            's3' => ['access_key_id', 'secret_access_key'],
            'google' => ['type', 'client_email', 'private_key', 'private_key_id'],
            'graph' => ['client_id', 'client_secret'],
        };
        foreach ($fields as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '' || strlen($data[$field]) > 8192) {
                throw ProviderBytes::unavailable();
            }
        }
        if (($provider === 'google' && ($data['type'] !== 'service_account' || ! filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)))
            || ($provider === 'graph' && ! preg_match('/^'.self::PROFILE.'$/D', $data['client_id']))
            || ($provider === 's3' && (! preg_match('/^[A-Z0-9]{16,128}$/D', $data['access_key_id'])
                || ! preg_match('~^[a-zA-Z0-9/+=]{40}$~D', $data['secret_access_key'])))) {
            throw ProviderBytes::unavailable();
        }

        return array_intersect_key($data, array_flip($fields));
    }

    public function folder(Tenant $tenant, string $namespace, int $objectId): string
    {
        $profile = $this->active($tenant);
        if ($profile) {
            abort_unless(self::enabled($profile->provider), 403);

            return 'clubunio/storage/'.$profile->id.'/'.$namespace.'/'.$tenant->id.'/'.$objectId.'/';
        }
        abort_unless($this->legacyTenant($tenant), 403);

        return 'clubunio/'.$namespace.'/'.$tenant->id.'/'.$objectId.'/';
    }

    public static function parse(string $folder): ?array
    {
        if (! preg_match('~^clubunio/storage/('.self::PROFILE.')/(registrations|members|expenses)/([1-9][0-9]*)/([1-9][0-9]*)/$~D', $folder, $m)) {
            return null;
        }

        return ['profile' => $m[1], 'namespace' => $m[2], 'tenant' => $m[3], 'object' => $m[4]];
    }

    private function profile(Tenant $tenant, array $path): object
    {
        abort_unless($path['tenant'] === (string) $tenant->id, 404);

        $profile = $this->profiles($tenant)->where('id', $path['profile'])->first();
        abort_unless($profile, 404);

        return $profile;
    }

    private function azure(object $profile): array
    {
        try {
            return ['enabled' => true, 'account' => $profile->account, 'container' => $profile->container,
                'key' => Crypt::decryptString($profile->encrypted_key)];
        } catch (Throwable) {
            throw new RuntimeException('Document storage unavailable', 503);
        }
    }

    public function expensePath(Tenant $tenant, string $reference, ?int $lineId = null): array
    {
        if (str_starts_with($reference, 'clubunio/storage/')) {
            $folder = dirname($reference).'/';
            $p = self::parse($folder);
            abort_unless($p && $folder.basename($reference) === $reference && $p['namespace'] === 'expenses' && $p['tenant'] === (string) $tenant->id
                && ($lineId === null || $p['object'] === (string) $lineId) && self::filename(basename($reference)), 404);

            return [$folder, basename($reference)];
        }
        abort_unless($this->legacyTenant($tenant), 404);
        if (str_starts_with($reference, 'clubunio/')) {
            $owner = 'clubunio/expenses/'.$tenant->id.'/'.($lineId === null ? '' : $lineId.'/');
            abort_unless(str_starts_with($reference, $owner), 404);
        }
        (new AzureDocumentReader)->blobPath('azure.blob://files/', $reference);

        return ['azure.blob://files/', $reference];
    }

    public function readExpense(Tenant $tenant, string $reference): string
    {
        return $this->read($tenant, ...$this->expensePath($tenant, $reference));
    }

    public static function filename(string $filename): bool
    {
        return (bool) preg_match('/^'.self::PROFILE.'\.(pdf|png|jpg|webp)$/D', $filename);
    }

    public function read(Tenant $tenant, string $folder, string $filename): string
    {
        $path = self::parse($folder);
        if (! $path) {
            abort_if(str_starts_with($folder, 'clubunio/storage/'), 404);
            abort_unless($this->legacyTenant($tenant), 404);

            return app(AzureDocumentReader::class)->read($folder, $filename);
        }
        abort_unless(self::filename($filename), 404);
        $profile = $this->profile($tenant, $path);
        if ($profile->provider === 'azure') {
            return app(AzureDocumentReader::class)->readAt($folder, $filename, $this->azure($profile));
        }

        if ($profile->provider === 'managed') {
            return app(ManagedDocumentDisk::class)->read($folder.$filename);
        }
        try {
            return ($this->remote($profile->provider) ?? throw ProviderBytes::unavailable())
                ->read($profile, $this->credentials($profile), $folder.$filename);
        } catch (Throwable $error) {
            throw ProviderBytes::unavailable($error->getCode() === 404 ? 404 : 503);
        }
    }

    public function put(Tenant $tenant, string $folder, string $filename, string $bytes, string $mime): void
    {
        $path = self::parse($folder);
        if (! $path) {
            abort_if(str_starts_with($folder, 'clubunio/storage/'), 404);
            abort_unless($this->legacyTenant($tenant), 404);
            app(RegistrationBlobStore::class)->put($folder, $filename, $bytes, $mime);

            return;
        }
        abort_unless(self::filename($filename), 422);
        $profile = $this->profile($tenant, $path);
        abort_unless(self::enabled($profile->provider), 403);
        if ($profile->provider === 'azure') {
            app(RegistrationBlobStore::class)->putAt($folder, $filename, $bytes, $mime, $this->azure($profile));
        } elseif ($profile->provider === 'managed') {
            app(ManagedDocumentDisk::class)->put($folder.$filename, $bytes);
        } else {
            abort_unless(strlen($bytes) > 0 && strlen($bytes) <= AzureDocumentReader::MAX_BYTES, 422);
            try {
                ($this->remote($profile->provider) ?? throw ProviderBytes::unavailable())
                    ->put($profile, $this->credentials($profile), $folder.$filename, $bytes, $mime);
            } catch (Throwable $error) {
                throw ProviderBytes::unavailable($error->getCode() === 409 ? 409 : 503);
            }
        }
    }
}
