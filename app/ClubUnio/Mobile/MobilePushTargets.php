<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BusReservationService, ExpenseService, MemberChangeService, ModulePermissionService};
use Illuminate\Support\Facades\DB;

/** Explicit source identities; document receipt/file/archive IDs never share a namespace. */
final class MobilePushTargets
{
    public const KINDS = ['chat', 'announcement', 'invoice', 'planning', 'expense', 'member', 'member_change', 'registration',
        'bus', 'document_receipt', 'document_file', 'document_archive', 'penalty'];

    public function authorize(Account $actor, Tenant $tenant, string $kind, int $id): array
    {
        abort_unless($id > 0 && in_array($kind, self::KINDS, true), 404);
        app(MobileAccess::class)->enter($actor, $tenant);
        $extra = [];
        try { switch ($kind) {
            case 'expense': app(ExpenseService::class)->visible($actor, $tenant)->findOrFail($id); break;
            case 'member': app(MobileMemberData::class)->member($actor, $tenant, $id); break;
            case 'member_change':
                $change = DB::connection('clubunio')->table('clubora.member_change_requests')->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
                app(MobileMemberData::class)->member($actor, $tenant, (int) $change->member_id);
                if (! app(ModulePermissionService::class)->allows($actor, $tenant, 'member_changes', 'view')) {
                    abort_unless((int) $change->requested_by === (int) $actor->id, 403);
                    app(MemberChangeService::class)->represented($actor, $tenant, (int) $change->member_id);
                }
                $extra['member_id'] = (int) $change->member_id;
                break;
            case 'registration': app(MobileRegistrationData::class)->registration($actor, $tenant, $id); break;
            case 'bus': app(MobileBusData::class)->visible($actor, $tenant)->where('id', $id)->firstOrFail(); break;
            case 'document_receipt': case 'document_file': case 'document_archive':
                app(MobileMemberDocuments::class)->view($actor, $tenant, $id, substr($kind, 9)); break;
            case 'penalty': app(MobilePenaltyAccess::class)->notice($actor, $tenant, $id); break;
            default: abort_unless(app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $kind, $id, false), 404);
        } } catch (\Illuminate\Database\RecordNotFoundException) { abort(404); }
        return ['kind' => $kind, 'object_id' => $id] + $extra;
    }
}
