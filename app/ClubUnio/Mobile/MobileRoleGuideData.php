<?php
namespace App\ClubUnio\Mobile;
use App\ClubUnio\Models\{Account,Tenant};
use App\ClubUnio\Services\{MemberAccessService,ModulePermissionService,MobileModuleSettings,TeamPlanningService};
use Illuminate\Support\Facades\DB;

/** Explanatory hints from actual personal policies, never an authorization grant. */
final class MobileRoleGuideData {
    public function read(Account $actor,Tenant $tenant): array {
        app(MobileAccess::class)->enter($actor,$tenant); $access=app(MemberAccessService::class); $planning=app(TeamPlanningService::class);
        $visible=app(MobileModuleSettings::class)->visibleKeys($actor,$tenant);
        $planningVisible=config('clubunio-mobile.native_planning_enabled',false)&&in_array('Teamplanung',$visible,true);
        $teams=$planningVisible?app(MobileSeasonScope::class)->teams($planning->teams($actor,$tenant,true,'update'),$tenant)->exists():false;
        $absences=false;
        if($planningVisible) { try { $absences=$planning->absenceMembers($actor,$tenant,'create')->exists(); } catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { if($e->getStatusCode()!==403) throw $e; } }
        $membership=$access->membership($actor->fresh(),$tenant);
        $label=$membership?DB::connection('clubunio')->table('clubora.account_roles')->where('code',$membership->role)->where('active',true)->value('name'):null;
        return ['tenant_id'=>(int)$tenant->id,'account_id'=>(int)$actor->id,'clubName'=>$tenant->name,'roleLabel'=>$label??'Dein Vereinszugang',
            'canManageTeams'=>$teams&&config('clubunio-mobile.native_my_teams_enabled',false)&&in_array('Teams',$visible,true),
            'canRespond'=>$planningVisible&&($teams||($access->represented($actor,$tenant)->exists()&&app(MobileAccess::class)->teams($actor,$tenant)->exists())),'canRecordAbsences'=>$absences,
            'canManageResponses'=>$teams,'canReviewMemberChanges'=>in_array('Mitglieder',$visible,true)&&config('clubunio-mobile.native_members_enabled',false)
                &&app(ModulePermissionService::class)->allows($actor,$tenant,'member_changes','approve'),
            'canSendSquadPush'=>$teams&&app(MobilePushReady::class)->available()
                &&app(MobileSeasonScope::class)->teams($planning->teams($actor,$tenant,true,'export'),$tenant)->exists()];
    }
}
