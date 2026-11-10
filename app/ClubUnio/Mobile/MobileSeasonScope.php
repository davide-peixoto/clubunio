<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

/** An additional mobile-only restriction, never a replacement for authorization. */
final class MobileSeasonScope
{
    public function enabled(): bool
    {
        return request()->attributes->get('mobile.season_scope') === true;
    }

    public function current(Tenant $tenant): ?object
    {
        $key = 'mobile.current_season.'.$tenant->id.'.'.now('Europe/Zurich')->toDateString();
        $request = request();
        if (! $request->attributes->has($key)) {
            $settings = DB::connection('clubunio')->table('clubunio.tenant_settings')->where('tenant_id', $tenant->id)
                ->where('area', 'general')->where('key', 'SaisonID')->limit(2)->pluck('encrypted_value');
            $value = '';
            if ($settings->count() === 1 && is_string($settings->sole())) {
                try { $value = trim(Crypt::decryptString($settings->sole())); }
                catch (\Illuminate\Contracts\Encryption\DecryptException) { /* Invalid configuration stays closed. */ }
            }
            $season = null;
            if (preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
                $rows = DB::connection('clubunio')->table('clubunio.seasons')->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('legacy_id', $value)->orWhere(fn ($q) => $q->whereNull('legacy_id')->where('id', $value)))
                    ->limit(2)->get(['id', 'legacy_id', 'name', 'active', 'starts_on', 'ends_on']);
                // Imported iniParameter values refer to legacy IDs, not coincidental native IDs.
                $legacy = $rows->where('legacy_id', $value);
                $season = $legacy->count() === 1 ? $legacy->first() : ($legacy->isEmpty() && $rows->count() === 1 ? $rows->first() : null);
                if (! $season?->active) { $season = null; }
            }
            $request->attributes->set($key, $season);
        }

        return $request->attributes->get($key);
    }

    public function teams($query, Tenant $tenant, string $column = 'season_id')
    {
        $season = $this->current($tenant);

        return $season ? $query->where($column, $season->id) : $query->whereRaw('1=0');
    }

    public function finance($query, Tenant $tenant, string $column = 'season_id')
    {
        return $this->enabled() ? $this->teams($query, $tenant, $column) : $query;
    }

    public function table($query, Tenant $tenant, string $table)
    {
        if (! $this->enabled()) { return $query; }
        if ($table === 'team_seasons') { return $this->teams($query, $tenant); }
        if (in_array($table, ['team_events', 'team_players', 'team_staff', 'technical_leads', 'training_slots'], true)) {
            $teams = DB::connection('clubunio')->table('clubunio.team_seasons')->where('tenant_id', $tenant->id)->where('active', true);

            return $query->whereIn('team_season_id', $this->teams($teams, $tenant)->select('id'));
        }
        if ($table === 'team_absences') {
            $season = $this->current($tenant);

            return $season?->starts_on && $season?->ends_on ? $query->where('starts_on', '<=', $season->ends_on)->where('ends_on', '>=', $season->starts_on) : $query->whereRaw('1=0');
        }

        return $query;
    }
}
