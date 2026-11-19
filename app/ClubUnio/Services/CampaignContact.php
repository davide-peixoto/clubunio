<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Member, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

final class CampaignContact
{
    public static function isMinor(Member $member): bool
    {
        $today = now('Europe/Zurich')->toDateString();
        return $member->birth_date && $member->birth_date->toDateString() <= $today
            && $member->birth_date->copy()->addYears(18)->toDateString() > $today;
    }

    public static function baseline(Member $member): string
    {
        return hash('sha256', json_encode($member->only(['birth_date','mother_name','mother_email','father_name',
            'father_email','billing_recipient_id','street','house_number','postal_code','city']), JSON_THROW_ON_ERROR));
    }

    public function verify(Account $actor, Tenant $tenant, int $campaign, int $recipient, array $input): void
    {
        $service = app(MemberCampaignService::class);
        abort_unless($service->allowed($actor, $tenant), 403);
        $data = validator($input, [
            'parent' => 'required|in:mother,father', 'name' => 'required|string|max:160',
            'email' => 'nullable|email:rfc|max:254', 'street' => 'required|string|max:160',
            'postal_code' => 'required|string|max:20', 'city' => 'required|string|max:160',
            'evidence' => 'required|string|min:10|max:500',
        ], [], trans('campaign_ops.fields'))->validate();
        DB::connection('clubunio')->transaction(function () use ($service, $actor, $tenant, $campaign, $recipient, $data) {
            $c = $service->campaigns($tenant)->where('id', $campaign)->lockForUpdate()->firstOrFail();
            abort_if($c->released_at || $c->revoked_at, 409);
            $row = $service->recipients($tenant, $campaign)->where('id', $recipient)->lockForUpdate()->firstOrFail();
            $member = app(MemberAccessService::class)->activeMembers($tenant)->lockForUpdate()->findOrFail($row->member_id);
            abort_unless(self::isMinor($member), 422);
            $data['email'] = trim((string) ($data['email'] ?? ''));
            $parent = $data['parent'];
            abort_unless(trim((string) $member->{$parent.'_name'}) === trim($data['name'])
                && strtolower(trim((string) $member->{$parent.'_email'})) === strtolower($data['email']), 422);
            $service->recipients($tenant, $campaign)->where('id', $row->id)->update([
                'contact_encrypted' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'contact_baseline' => self::baseline($member), 'verified_by' => $actor->id, 'verified_at' => now(),
            ]);
            DB::connection('clubunio')->table('clubunio.object_changes')->insert([
                'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'campaign_recipients', 'object_id' => $row->id,
                'after_value' => json_encode(['guardian_verified' => true, 'campaign_id' => $campaign], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function details(Tenant $tenant, object $row, Member $member): ?array
    {
        if (! self::isMinor($member) || ! ($row->contact_encrypted ?? null) || ! $row->verified_by || ! $row->verified_at
            || ! hash_equals($row->contact_baseline, self::baseline($member))) return null;
        $actor = Account::find($row->verified_by);
        if (! $actor || ! app(MemberCampaignService::class)->allowed($actor, $tenant)) return null;
        return json_decode(Crypt::decryptString($row->contact_encrypted), true, 512, JSON_THROW_ON_ERROR);
    }

    public function address(Tenant $tenant, object $row, Member $member): array
    {
        $contact = $this->details($tenant, $row, $member);
        if ($contact) return $contact;
        abort_unless($member->birth_date && ! self::isMinor($member) && $member->birth_date->toDateString() <= now('Europe/Zurich')->toDateString(), 422);
        return ['name' => trim($member->first_name.' '.$member->last_name),
            'street' => trim($member->street.' '.$member->house_number), 'postal_code' => $member->postal_code,
            'city' => $member->city, 'email' => app(MemberCampaignService::class)->email($member)];
    }
}
