<?php

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $tenant = Tenant::where('slug', 'sv-sissach')->firstOrFail();
        // Existing explicit participants retain event-only access, never general team access.
        $rows = $db->table('clubora.team_participations as p')
            ->join('clubora.team_events as e', fn ($j) => $j->on('e.tenant_id', '=', 'p.tenant_id')->on('e.id', '=', 'p.event_id'))
            ->where('p.tenant_id', $tenant->id)->whereNotNull('e.legacy_id')
            ->whereIn('p.member_id', app(MemberAccessService::class)->activeMembers($tenant)->select('id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.team_players as t')->whereColumn('t.tenant_id', 'e.tenant_id')->whereColumn('t.team_season_id', 'e.team_season_id')->whereColumn('t.member_id', 'p.member_id')->where('t.active', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.team_staff as t')->whereColumn('t.tenant_id', 'e.tenant_id')->whereColumn('t.team_season_id', 'e.team_season_id')->whereColumn('t.member_id', 'p.member_id')->where('t.active', true))
            ->get(['p.event_id', 'p.member_id']);
        foreach ($rows as $row) {
            $db->table('clubora.team_invitations')->insertOrIgnore(['tenant_id' => $tenant->id, 'event_id' => $row->event_id, 'member_id' => $row->member_id]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
