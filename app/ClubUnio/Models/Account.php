<?php

namespace App\ClubUnio\Models;

use App\ClubUnio\Models\Concerns\UsesClubUnioSchema;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

class Account extends Authenticatable implements FilamentUser, HasName, HasTenants
{
    use UsesClubUnioSchema;

    protected $connection = 'clubunio';

    protected $guarded = ['*'];

    protected $hidden = ['password'];

    protected $casts = ['active' => 'boolean'];

    public function getRememberTokenName()
    {
        return '';
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'clubora.tenant_accounts')->withPivot('role');
    }

    public function getFilamentName(): string
    {
        return $this->display_name;
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->active ? $this->tenants()->wherePivotIn('role', ['admin', 'office'])->get() : collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->active && $tenant instanceof Tenant && $this->tenants()->whereKey($tenant->getKey())->wherePivotIn('role', ['admin', 'office'])->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'clubunio' && $this->active && $this->tenants()->wherePivotIn('role', ['admin', 'office'])->exists();
    }
}
