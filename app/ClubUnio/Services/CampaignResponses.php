<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, EventGuest, SponsorRunParticipant, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

final class CampaignResponses
{
    public function opened(Tenant $tenant, object $row): void
    {
        app(MemberCampaignService::class)->recipients($tenant, $row->campaign_id)->where('id', $row->id)
            ->whereNull('first_opened_at')->update(['first_opened_at' => now()]);
    }

    public function submit(Tenant $tenant, string $token, array $input): void
    {
        DB::connection('clubunio')->transaction(function () use ($tenant, $token, $input) {
            $service = app(MemberCampaignService::class);
            [$row, , $campaign] = $service->invitation($tenant, $token, true);
            abort_unless(in_array($campaign->kind, ['event','sponsor'], true)
                && app(CampaignOperations::class)->targetValid($tenant, $campaign), 409);
            $data = validator($input, ['answer' => 'required|in:yes,no', 'note' => 'nullable|string|max:500'], [], trans('campaign_ops.fields'))->validate();
            if ($campaign->kind === 'sponsor' && $data['answer'] === 'yes') {
                $pledge = SponsorRunMoney::fromWeb($input);
                $data['pledge'] = SponsorRunService::pledgeInput(array_intersect_key($pledge, array_flip([
                    'sponsor_name','amount_type','amount_cents','cap_cents','first_name','last_name','street','house_number',
                    'postal_code','city','country','email',
                ])) + ['request_key' => 'campaign-'.$row->id.'-'.bin2hex(random_bytes(10)),
                    'payment_mode' => 'invoice', 'recipient_type' => 'person']);
            }
            $service->recipients($tenant, $row->campaign_id)->where('id', $row->id)->update([
                'response_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'acknowledged_at' => now(), 'token_hash' => null, 'token_encrypted' => null,
            ]);
        });
    }

    public function review(Account $actor, Tenant $tenant, int $campaign, int $recipient, bool $approve): void
    {
        app(CampaignOperations::class)->authorise($actor, $tenant);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $campaign, $recipient, $approve) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $service = app(MemberCampaignService::class);
            $c = $service->campaigns($tenant)->where('id', $campaign)->lockForUpdate()->firstOrFail();
            $q = $service->recipients($tenant, $campaign)->where('id', $recipient);
            $row = (clone $q)->lockForUpdate()->firstOrFail();
            abort_unless($row->response_encrypted && ! $row->response_reviewed_at, 409);
            $data = json_decode(Crypt::decryptString($row->response_encrypted), true, 512, JSON_THROW_ON_ERROR);
            $objectId = null;
            if ($approve) {
                abort_unless(! $c->revoked_at && $service->deliveryMember($tenant, $row)
                    && app(CampaignOperations::class)->targetValid($tenant, $c), 409);
                $permissions = app(ModulePermissionService::class);
                $permissions->authorize($actor, $tenant, $c->kind === 'event' ? 'events' : 'sponsor_runs', 'create');
                if ($data['answer'] === 'yes' && $c->kind === 'event') {
                    $existing = EventGuest::where('tenant_id', $tenant->id)->where('member_id', $row->member_id)
                        ->where('club_event_id', $c->target_id)->where('active', true)->first();
                    $guest = $existing ?: app(DossierMaintenanceService::class)->save($actor, $tenant, EventGuest::class,
                        $row->member_id, null, ['club_event_id' => $c->target_id, 'active' => true, 'revision' => 0]);
                    $objectId = $guest->id;
                } elseif ($data['answer'] === 'yes' && $c->kind === 'sponsor') {
                    $participant = SponsorRunParticipant::where('tenant_id', $tenant->id)->where('member_id', $row->member_id)
                        ->where('event_id', $c->target_id)->sole();
                    $objectId = app(SponsorRunService::class)->addPledge($actor, $tenant, $participant->id, $data['pledge'])->id;
                }
            }
            $q->update(['response_reviewed_at' => now(), 'response_reviewed_by' => $actor->id,
                'response_object_id' => $objectId, 'response_disposition' => $approve ? 'approved' : 'rejected']);
            DB::connection('clubunio')->table('clubunio.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'campaign_recipients', 'object_id' => $row->id,
                'after_value' => json_encode(['response' => $approve ? 'approved' : 'rejected', 'linked_object_id' => $objectId], JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
