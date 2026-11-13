<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};

final class MobileBootstrapState {
    public function enabled(): bool { return config('clubunio-mobile.bootstrap.enabled',false)===true; }
    public function origin(Request $request): string {
        $origin=$request->getSchemeAndHttpHost(); $parts=parse_url($origin);
        abort_unless($request->isSecure()&&is_array($parts)&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['path'])
            &&in_array($origin,config('clubunio-mobile.bootstrap.origins',[]),true),503);
        return $origin;
    }
    private function value(Tenant $tenant,string $key): ?string {
        $rows=DB::connection('clubunio')->table('clubora.tenant_settings')->where('tenant_id',$tenant->id)->where('area','app')->where('key',$key)
            ->where('enabled',true)->where('is_secret',false)->limit(2)->pluck('encrypted_value');
        if($rows->isEmpty()) return null;
        if($rows->count()!==1) throw new \LogicException('Ambiguous app setting');
        return Crypt::decryptString($rows->sole());
    }
    public function registrationActive(Tenant $tenant): bool {
        if(!$this->enabled()) return true;
        try { $value=$this->value($tenant,'RegistrationActive'); return $value===null||in_array(mb_strtolower(trim($value)),['1','true','ja','y'],true); }
        catch(\Throwable) { return false; }
    }
    private function status(?Tenant $tenant): array {
        $maintenance=!config('clubunio-mobile.enabled',false)||config('clubunio-mobile.bootstrap.maintenance_mode',false);
        $message=(string)config('clubunio-mobile.bootstrap.maintenance_message','Die App ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.');
        if($tenant) {
            try {
                $value=$this->value($tenant,'MaintenanceMode');
                if($value!==null&&!in_array(mb_strtolower(trim($value)),['0','false','nein','n'],true)) {
                    $maintenance=true; $message=$this->value($tenant,'MaintenanceMessage')??'Die App wird gewartet. Bitte versuche es später erneut.';
                }
            } catch(\Throwable) { $maintenance=true; $message='Der Appbetrieb konnte nicht geprüft werden. Bitte versuche es später erneut.'; }
        }
        return ['tenant_id'=>$tenant?(int)$tenant->id:null,'maintenanceMode'=>(bool)$maintenance,
            'maintenanceMessage'=>$maintenance?mb_substr($message,0,2000):null,'loginAllowed'=>!$maintenance];
    }
    private function publicSurface(string $origin,?Tenant $tenant,string $surface): bool {
        if(!config('clubunio-mobile.public_identity.enabled',false)||!config('clubunio-mobile.public_identity.'.$surface.'_enabled',false)) return false;
        $policy=app(MobilePublicIdentityPolicy::class);
        try { $policy->authorizeOrigin($origin); } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) { return false; }
        $clubs=$tenant?collect([$tenant]):Tenant::whereIn('slug',config('clubunio-mobile.public_identity.tenant_slugs',[]))->limit(100)->get();
        foreach($clubs as $club) {
            if($this->status($club)['loginAllowed']&&$policy->enabled($club,$surface)&&($surface!=='registration'||$this->registrationActive($club))) return true;
        }
        return false;
    }
    public function publicState(Request $request): array {
        abort_unless($this->enabled(),503); $origin=$this->origin($request);
        abort_if($request->query->count()!==0,422);
        $tenant=app(TenantDomainService::class)->resolve($request);
        $data=$this->status($tenant);
        $data['registrationActive']=$data['loginAllowed']&&$this->publicSurface($origin,$tenant,'registration');
        $data['membershipActive']=$data['loginAllowed']&&$this->publicSurface($origin,$tenant,'membership');
        $data['announcements']=$tenant?app(MobileAppAnnouncements::class)->active($tenant,false):[];
        $data['roleGuideAvailable']=false;
        $data['pushAdminAvailable']=false;
        $data['strongLoginAvailable']=config('clubunio-security.enabled') === true && config('clubunio-security.origin') === $origin;
        return $data;
    }
    public function personalState(Account $actor,Tenant $tenant): array {
        abort_unless($this->enabled(),503); app(MobileAccess::class)->enter($actor,$tenant);
        return $this->status($tenant)+['registrationActive'=>$this->registrationActive($tenant)&&app(MobilePublicIdentityPolicy::class)->enabled($tenant,'registration'),
            'membershipActive'=>app(MobilePublicIdentityPolicy::class)->enabled($tenant,'membership'),
            'roleGuideAvailable'=>(bool)config('clubunio-mobile.native_role_guide_enabled',false),
            'pushAdminAvailable'=>config('clubunio-mobile.native_push_admin_enabled',false)
                &&app(MobileAdminNoticeAccess::class)->administrator($actor,$tenant),
            'announcements'=>app(MobileAppAnnouncements::class)->active($tenant,true)];
    }
    /** Root calls before anonymous password login; custom club always comes from verified Host. */
    public function authorizeLogin(Request $request): void {
        if(!$this->enabled()) return;
        $this->origin($request); $state=$this->status(app(TenantDomainService::class)->resolve($request));
        abort_unless($state['loginAllowed'],503,'Die App wird gewartet. Bitte versuche es später erneut.');
    }
    /** Root exempts only GET tenant/bootstrap; global logout/session deletion stays possible. */
    public function authorizeTenant(Request $request,Tenant $tenant): void {
        if(!$this->enabled()) return;
        if($request->isMethod('GET')&&$request->route()?->getActionName()===MobileBootstrapController::class.'@personal') return;
        abort_unless($this->status($tenant)['loginAllowed'],503,'Die App wird gewartet. Bitte versuche es später erneut.');
    }
}
