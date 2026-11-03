<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\DB;

final class MobileModuleSettings
{
    public const MODULES = [
        'Spesen'=>'Spesen', 'Mitglieder'=>'Mitglieder', 'Platzverfugbarkeit'=>'Anlagenstatus',
        'MitgliederAnmeldungen'=>'Mitgliederanmeldungen', 'Teams'=>'Meine Mannschaften',
        'Teamplanung'=>'Teamplanung', 'Trainingsplaner'=>'KI-Trainingsplanung',
        'BusReservationen'=>'Busreservierungen', 'Dokumente'=>'Dokumente', 'Sponsorenlauf'=>'Sponsorenlauf',
    ];

    public function options(Tenant $tenant): array
    {
        return DB::connection('clubunio')->table('clubora.mobile_module_options')
            ->where('tenant_id',$tenant->id)->where('active',true)->orderBy('sort_order')
            ->pluck('name','key')->all();
    }

    public function visibleKeys(Account $actor, Tenant $tenant): array
    {
        $available = array_keys($this->options($tenant));
        $db = DB::connection('clubunio');
        $link = $db->table('clubora.tenant_accounts')->where('tenant_id',$tenant->id)->where('account_id',$actor->id)->first();
        if (! $link) { return []; }
        $profileId = $link->mobile_profile_id ?? ($link->role === 'profile' ? $link->access_profile_id : null);
        if ($profileId === null) { return $available; }
        $profile = $db->table('clubora.access_profiles')->where('tenant_id',$tenant->id)
            ->where('id',$profileId)->where('active',true)->first();
        if (! $profile) { return []; }
        return $profile->mobile_modules === null ? $available
            : array_values(array_intersect($available,json_decode($profile->mobile_modules,true,512,JSON_THROW_ON_ERROR)));
    }

    public function assign(Account $actor, Tenant $tenant, int $membershipId, array $input): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$membershipId,$input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAdministrationService::class)->allowed($actor,$tenant),403);
            $data = \Illuminate\Support\Facades\Validator::make($input,[
                'mobile_profile_id'=>['nullable','integer',\Illuminate\Validation\Rule::exists('clubunio.clubora.access_profiles','id')->where(fn ($q)=>$q->where('tenant_id',$tenant->id)->where('active',true))],
                'revision'=>'required|integer|min:1', 'reason'=>'required|string|min:10|max:500',
            ])->validate();
            $link = \App\ClubUnio\Models\TenantAccount::where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($membershipId);
            if ((int) $link->revision !== (int) $data['revision']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['mobile_profile_id'=>'Benutzer wurde inzwischen geändert. Bitte neu öffnen.']);
            }
            $before = ['mobile_profile_id'=>$link->mobile_profile_id,'revision'=>$link->revision];
            $link->forceFill(['mobile_profile_id'=>$data['mobile_profile_id'] ?? null,'revision'=>$link->revision+1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert([
                'tenant_id'=>$tenant->id,'actor_id'=>$actor->id,'object_type'=>'tenant_accounts','object_id'=>$link->id,
                'before_value'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after_value'=>json_encode(['mobile_profile_id'=>$link->mobile_profile_id,'revision'=>$link->revision,'reason'=>$data['reason']],JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
