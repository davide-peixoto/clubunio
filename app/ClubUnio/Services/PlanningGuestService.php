<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanningGuestService
{
    public function __construct(private TeamPlanningService $planning) {}

    public function save(Account $actor, Tenant $tenant, int $eventId, ?int $id, array $input, bool $delete = false): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $eventId, $id, $input, $delete) {
            $p = $this->planning;
            $p->lock($tenant);
            $event = $p->event($actor, $tenant, $eventId, true);
            abort_unless($event->status === 'AKTIV', 409);
            $query = $p->table($tenant, 'team_guests')->where('event_id', $eventId)->whereNull('deleted_at');
            $old = $id ? (clone $query)->where('id', $id)->first() : null;
            abort_if($id && ! $old, 404);
            $p->conflict($old, $input);
            if ($delete) {
                $data = ['deleted_at' => now()->toIso8601String()];
            } else {
                $options = $p->options($tenant);
                $data = Validator::make($input, [
                    'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
                    'status' => ['required', Rule::in($options['participation']->where('active', true)->keys()->all())],
                    'attendance' => ['required', Rule::in($options['attendance']->where('active', true)->keys()->all())],
                    'comment' => 'nullable|string|max:1000',
                ])->validate();
                $count = $p->effectiveCount($tenant, $event) - (int) ($old?->status === 'DABEI');
                if ($data['status'] === 'DABEI' && $event->capacity && $count >= $event->capacity) {
                    throw ValidationException::withMessages(['status' => 'Die maximale Teilnehmerzahl ist erreicht.']);
                }
                $data['comment_encrypted'] = empty($data['comment']) ? null : Crypt::encryptString($data['comment']);
                unset($data['comment']);
            }
            $data += ['revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()->toIso8601String()];
            if ($old) {
                $query->where('id', $id)->update($data);
            } else {
                $id = $query->insertGetId($data + ['tenant_id' => $tenant->id, 'event_id' => $eventId]);
            }
            $p->audit($actor, $tenant, 'team_guests', $id, $old?->revision, $data['revision']);
        });
    }

    public function invite(Account $actor, Tenant $tenant, int $eventId, int $member, bool $remove = false): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $eventId, $member, $remove) {
            $p = $this->planning;
            $p->lock($tenant);
            $event = $p->event($actor, $tenant, $eventId, true);
            abort_unless($event->status === 'AKTIV', 409);
            abort_unless(app(MemberAccessService::class)->activeMembers($tenant)->whereKey($member)->exists(), 404);
            $query = $p->table($tenant, 'team_invitations')->where('event_id', $eventId)->where('member_id', $member);
            if ($remove) {
                abort_unless((clone $query)->exists(), 404);
                $query->delete();
            } else {
                if ($p->roster($tenant, $event)->whereKey($member)->exists()) {
                    return;
                }
                // Invitations start open even in opt-out events and never silently take a place.
                $query->insert(['tenant_id' => $tenant->id, 'event_id' => $eventId, 'member_id' => $member]);
                $part = $p->table($tenant, 'team_participations')->where('event_id', $eventId)->where('member_id', $member);
                if (! (clone $part)->exists()) {
                    $part->insert(['tenant_id' => $tenant->id, 'event_id' => $eventId, 'member_id' => $member, 'status' => 'OFFEN']);
                }
            }
            $p->reconcile($tenant, $event, $member);
            $p->table($tenant, 'object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'team_invitations', 'object_id' => $eventId,
                'after_value' => json_encode(['member_id' => $member, 'operation' => $remove ? 'removed' : 'invited'])]);
        });
    }
}
