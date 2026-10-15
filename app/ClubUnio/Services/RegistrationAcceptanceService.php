<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationAcceptanceService
{
    /** Called inside the review transaction, with the tenant and registration locked. */
    public function accept(Account $actor, Tenant $tenant, Registration $r, array $input): Member
    {
        if (DB::connection('clubunio')->transactionLevel() === 0) {
            throw new \LogicException('Registration acceptance requires the review transaction.');
        }
        abort_unless(app(RegistrationReviewService::class)->canReview($actor, $tenant, $r), 403);
        if (app(DocumentUploadService::class)->table()->where('tenant_id', $tenant->id)->where('kind', 'registration')->where('object_id', $r->id)->where('state', 'pending')->exists()) {
            throw ValidationException::withMessages(['status_id' => 'Anhänge warten auf die Azure-Übertragung. Bitte zuerst Synchronisation abschliessen.']);
        }
        if (! $r->details_complete) {
            throw ValidationException::withMessages(['status_id' => 'Die vollständigen Anmeldedaten fehlen.']);
        }
        $office = $actor->canAccessTenant($tenant);
        if (! $office && array_key_exists('member_id', $input) && (string) $input['member_id'] !== (string) $r->member_id) {
            abort(403);
        }
        $id = $input['member_id'] ?? $r->member_id;
        $member = $id ? Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($id) : null;
        if ($member && ! hash_equals(MemberService::version($member), $input['member_version'] ?? '')) {
            throw ValidationException::withMessages(['member_id' => 'Bitte den aktuellen Mitgliederdatensatz prüfen und die Auswahl erneut bestätigen.']);
        }
        if (! $member && $this->possibleDuplicates($tenant, $r)->exists()) {
            throw ValidationException::withMessages(['member_id' => 'Es gibt ein mögliches bestehendes Mitglied. Die Geschäftsstelle muss die Zuordnung prüfen.']);
        }
        $db = DB::connection('clubunio');
        $type = (int) ($input['member_type_id'] ?? $member?->type_id ?? 0);
        if (! $db->table('clubora.member_types')->where('tenant_id', $tenant->id)->where('active', true)->where('id', $type)->exists()) {
            throw ValidationException::withMessages(['member_type_id' => 'Bitte eine aktive Mitgliederart auswählen.']);
        }
        $documents = $db->table('clubora.registration_documents')->where('tenant_id', $tenant->id)->where('registration_id', $r->id)->where('active', true)->get();
        $documentType = $input['document_type_id'] ?? null;
        if ($documents->contains('kind', 'identity') && ! $db->table('clubora.document_types')->where('tenant_id', $tenant->id)->where('active', true)->where('id', $documentType)->exists()) {
            throw ValidationException::withMessages(['document_type_id' => 'Bitte den Dokumententyp für die Ausweise auswählen.']);
        }
        $returning = $member && ($member->exit_date || $member->status?->ends_membership);
        $member = app(MemberService::class)->fromRegistration($actor, $tenant, $r, $member, $type);
        $values = app(RegistrationDataService::class)->values($r);
        if (! empty($values['ahv'])) {
            $member->forceFill(['ahv_encrypted' => Crypt::encryptString($values['ahv'])]);
        }
        foreach ($documents as $document) {
            if ($document->kind === 'portrait') {
                $member->forceFill(['legacy_photo' => $document->storage_path.$document->filename]);
            } else {
                $db->table('clubora.member_documents')->insert([
                    'tenant_id' => $tenant->id, 'member_id' => $member->id, 'document_type_id' => $documentType,
                    'filename' => $document->filename, 'original_filename' => $document->original_filename ?: $document->filename,
                    'storage_path' => $document->storage_path, 'registration_document_id' => $document->id,
                ]);
            }
        }
        $member->save();
        if (! empty($values['notes'])) {
            $db->table('clubora.member_notes')->insert(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'body' => $values['notes']]);
        }
        $player = null;
        if ($r->team_season_id) {
            $player = $db->table('clubora.team_players')->where('tenant_id', $tenant->id)->where('member_id', $member->id)
                ->where('team_season_id', $r->team_season_id)->where('active', true)->value('id');
            if (! $player) {
                // Do not reactivate historical invoice-bound rows or move other memberships.
                $player = $db->table('clubora.team_players')->insertGetId(['tenant_id' => $tenant->id,
                    'member_id' => $member->id, 'team_season_id' => $r->team_season_id, 'active' => true]);
                $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => 'team_players', 'object_id' => $player,
                    'after_value' => json_encode(['registration_id' => $r->id, 'member_id' => $member->id, 'team_season_id' => $r->team_season_id], JSON_THROW_ON_ERROR)]);
            }
        }
        $parents = app(RegistrationParentService::class)->propose($actor, $tenant, $r, $member);
        $r->forceFill(['member_id' => $member->id, 'accepted_at' => now(), 'acceptance_summary' => [
            'returning' => (bool) $returning, 'team_player_id' => $player, 'parent_link_ids' => $parents,
            'document_count' => $documents->count(), 'actor_id' => $actor->id,
        ]]);

        return $member;
    }

    public function possibleDuplicates(Tenant $tenant, Registration $r)
    {
        return Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')->where(function ($q) use ($r) {
            $q->where(function ($q) use ($r) {
                $q->whereRaw('lower(trim(first_name)) = lower(trim(?))', [$r->first_name])
                    ->whereRaw('lower(trim(last_name)) = lower(trim(?))', [$r->last_name]);
                if ($r->birth_date) {
                    $q->where('birth_date', $r->birth_date->format('Y-m-d'));
                }
            });
            if ($r->email) {
                $q->orWhereRaw('lower(trim(email)) = lower(trim(?))', [$r->email]);
            }
        });
    }
}
