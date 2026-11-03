<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\TeamPlanningService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Validation\ValidationException;

final class MobilePlanningAttendance
{
    public function set(Account $actor, Tenant $tenant, object $event, array $input, bool $suggestion = false): void
    {
        $p = app(TeamPlanningService::class);
        // The controller holds the tenant and personal session locks for the complete batch.
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 500);
        $p->authorizeOperation($actor, $tenant, 'update');
        abort_unless($event->status === 'AKTIV', 409);
        abort_if(Time::parse($event->starts_at)->isFuture(), 409, 'Anwesenheit erst nach Terminbeginn.');
        $member = (int) $input['MitgliederID'];
        abort_unless($p->roster($tenant, $event)->whereKey($member)->exists(), 404);
        $query = $p->table($tenant, 'team_participations')->where('event_id', $event->id)->where('member_id', $member);
        $old = (clone $query)->first();
        $p->conflict($old, ['revision' => $input['Revision']]);
        abort_if(! $old && (int) $input['Revision'] !== 0, 409);
        $status = $old->status ?? ($event->mode === 'ABMELDEN' ? 'DABEI' : 'OFFEN');
        if ($suggestion) {
            abort_unless($status === $input['TeilnahmeStatus'] && ($old->attendance ?? 'OFFEN') === 'OFFEN', 409, 'Vorschlaege inzwischen geaendert.');
            $attendance = match ($status) { 'DABEI' => 'ANWESEND', 'ABWESEND' => 'ENTSCHULDIGT', default => abort(422) };
        } else { $attendance = $input['AnwesenheitsStatus']; }
        if (! $p->table($tenant, 'planning_options')->where('category', 'attendance')->where('code', $attendance)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['AnwesenheitsStatus' => 'Ungueltiger Anwesenheitsstatus.']);
        }
        $data = ['attendance' => $attendance, 'attendance_updated_at' => now()->toIso8601String(),
            'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()->toIso8601String()];
        if (! $suggestion) { $data['attendance_comment_encrypted'] = empty($input['AnwesenheitsKommentar']) ? null : Crypt::encryptString($input['AnwesenheitsKommentar']); }
        // Attendance must never overwrite a private participation reason or absence provenance.
        if ($old) { $query->update($data); }
        else { $query->insert($data + ['tenant_id' => $tenant->id, 'event_id' => $event->id, 'member_id' => $member, 'status' => $status, 'source' => 'trainer']); }
        $p->audit($actor, $tenant, 'team_participations', $old->id ?? $event->id, $old?->revision, $data['revision']);
    }
}
