<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

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
            $today = now('Europe/Zurich')->toDateString();
            $rows = DB::connection('clubunio')->table('clubora.seasons')->where('tenant_id', $tenant->id)
                ->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)
                ->limit(2)->get(['id', 'starts_on', 'ends_on']);
            // Missing dates and ambiguous configuration must never expose the full archive.
            $request->attributes->set($key, $rows->count() === 1 ? $rows->first() : null);
        }

        return $request->attributes->get($key);
    }

    public function teams($query, Tenant $tenant, string $column = 'season_id')
    {
        $season = $this->current($tenant);

        return $season ? $query->where($column, $season->id) : $query->whereRaw('1=0');
    }

    public function table($query, Tenant $tenant, string $table)
    {
        if (! $this->enabled()) { return $query; }
        if ($table === 'team_seasons') { return $this->teams($query, $tenant); }
        if (in_array($table, ['team_events', 'team_players', 'team_staff', 'technical_leads', 'training_slots'], true)) {
            $teams = DB::connection('clubunio')->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('active', true);

            return $query->whereIn('team_season_id', $this->teams($teams, $tenant)->select('id'));
        }
        if ($table === 'team_absences') {
            $season = $this->current($tenant);

            return $season ? $query->where('starts_on', '<=', $season->ends_on)->where('ends_on', '>=', $season->starts_on) : $query->whereRaw('1=0');
        }

        return $query;
    }
}
