<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Member, Tenant, TeamPlayer, TeamStaff, Registration};
use App\ClubUnio\Services\{FamilyService, MemberAccessService, ModulePermissionService, RegistrationDataService, RegistrationReviewService, SignageService, MemberTeamAccessService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileTeamsData
{
    public function table(Tenant $tenant, string $table) { return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id); }

    public function teams(Account $actor, Tenant $tenant)
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        $access = app(MemberAccessService::class);
        $q = DB::connection('clubunio')->table('clubora.team_seasons as ts')
            ->join('clubora.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))
            ->join('clubora.seasons as s', fn ($j) => $j->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
            ->where('ts.tenant_id', $tenant->id)->where('ts.active', true)->where('t.active', true);
        app(MobileSeasonScope::class)->teams($q, $tenant, 'ts.season_id');
        if (! app(ModulePermissionService::class)->allows($actor, $tenant, 'teams', 'view')) {
            abort_if($access->membership($actor, $tenant)?->role === 'profile', 403);
            $own = $access->represented($actor, $tenant)->pluck('id');
            $responsible = $access->responsibleTeams($actor, $tenant);
            $q->where(function ($q) use ($tenant, $own, $responsible) {
                $q->whereIn('ts.id', $responsible);
                foreach (['team_players', 'team_staff'] as $table) {
                    $q->orWhereIn('ts.id', $this->table($tenant, $table)->where('active', true)->whereIn('member_id', $own)->select('team_season_id'));
                }
            });
        }
        return $q->select(['ts.id', 'ts.team_id', 'ts.season_id', 'ts.label']);
    }

    private function capabilities(Account $actor, Tenant $tenant): array
    {
        $permissions = app(ModulePermissionService::class);
        return ['CanAddAssignments' => $permissions->allows($actor, $tenant, 'teams', 'create'),
            'CanRemoveAssignments' => $permissions->allows($actor, $tenant, 'teams', 'update') && $permissions->allows($actor, $tenant, 'teams', 'deactivate')];
    }

    private function teamDto(Account $actor, Tenant $tenant, object $team, ?array $capabilities = null): array
    {
        return ['TeamSaisonID' => (int) $team->id, 'TeamID' => (int) $team->team_id, 'SaisonID' => (int) $team->season_id,
            'Bezeichnung' => $team->label] + ($capabilities ?? $this->capabilities($actor, $tenant));
    }

    public function summaries(Request $r, Account $actor, Tenant $tenant): array
    {
        $d = $r->validate(['start' => 'nullable|integer|min:0|max:100000', 'recperpage' => 'nullable|integer|min:1|max:100']);
        $q = $this->teams($actor, $tenant);
        $capabilities = $this->capabilities($actor, $tenant);
        $rows = (clone $q)->selectRaw('COUNT(*) OVER () AS mobile_total')->orderBy('ts.team_id')->orderBy('ts.id')
            ->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 100)->get();
        // An out-of-range/empty page has no window row from which to read total.
        $total = $rows->isNotEmpty() ? (int) $rows->first()->mobile_total : (clone $q)->count();
        return ['Teams' => $rows->map(fn ($row) => $this->teamDto($actor, $tenant, $row, $capabilities))->all(), 'totalRecordCount' => $total];
    }

    public function team(Account $actor, Tenant $tenant, int $id): object
    {
        return $this->teams($actor, $tenant)->where('ts.id', $id)->firstOrFail();
    }

    public function assignment($row, string $kind): array
    {
        return [($kind === 'staff' ? 'TeamStaffID' : 'TeamlistID') => (int) $row->id,
            'TeamSaisonID' => (int) $row->team_season_id, 'MitgliederID' => (int) $row->member_id,
            '_version' => FamilyService::version($row), ...($kind === 'staff' ? ['TeamSatffFunktionID' => $row->staff_function_id] : [])];
    }

    public function details(Account $actor, Tenant $tenant, int $id): array
    {
        $team = $this->team($actor, $tenant, $id);
        $access = app(MemberAccessService::class);
        $active = $access->activeMembers($tenant)->select('clubora.members.id');
        $staff = TeamStaff::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('team_season_id', $id)->where('active', true)->whereIn('member_id', clone $active)->orderBy('id')->get();
        $players = TeamPlayer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('team_season_id', $id)->where('active', true)->whereIn('member_id', clone $active)->orderBy('id')->get();
        $ids = $staff->pluck('member_id')->merge($players->pluck('member_id'))->unique();
        $mode = app(MemberTeamAccessService::class)->forAuthorizedTeam($actor, $tenant, $id);
        $staffIds = array_fill_keys($staff->pluck('member_id')->all(), true);
        $direct = app(MobileMemberData::class)->direct($actor, $tenant);
        $own = array_fill_keys($access->represented($actor, $tenant)->whereIn('clubora.members.id', $ids)->pluck('id')->all(), true);
        $full = in_array($mode, ['full', 'trainer'], true);
        $members = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name',
            'email', 'mobile', 'city', 'birth_date', 'street', 'house_number', 'postal_code', 'phone_private', 'phone_business',
            'mother_name', 'mother_email', 'mother_mobile', 'father_name', 'father_email', 'father_mobile'])->mapWithKeys(function ($m) use ($mode, $staffIds, $own, $full, $direct, $id) {
            $d = ['MitgliederID' => (int) $m->id, 'Vorname' => $m->first_name, 'Nachname' => $m->last_name,
                'TeamSaisonID' => $id, 'CanOpenDossier' => $mode !== 'parent' || isset($staffIds[$m->id]),
                'ContactOnly' => !$full, 'CanRequestChanges' => $mode === 'trainer' || ($mode === 'full' && isset($own[$m->id]) && !$direct)];
            if ($d['CanOpenDossier']) {
                $d += ['Email' => $m->email, 'Mobile' => $m->mobile, 'Ort' => $m->city];
            }
            if ($full) {
                foreach (['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'phone_private' => 'TelefonPrivat',
                    'phone_business' => 'TelefonGeschaeft', 'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter', 'mother_mobile' => 'MobileMutter',
                    'father_name' => 'Vater', 'father_email' => 'EmailVater', 'father_mobile' => 'MobileVater'] as $from => $to) { $d[$to] = $m->$from; }
                $d['Geburtsdatum'] = $m->birth_date?->format('Y-m-d');
            }
            return [(int) $m->id => $d];
        });
        $functions = $this->table($tenant, 'staff_functions')->get(['id', 'code', 'name'])->keyBy('id');
        $person = function ($row, string $kind) use ($mode, $members, $id): array {
            $person = $members->get($row->member_id) ?? [];
            if ($mode === 'parent' && $kind === 'players') return array_intersect_key($person, array_flip(['MitgliederID', 'Vorname', 'Nachname']))
                + ['TeamSaisonID'=>$id, 'CanOpenDossier'=>false, 'ContactOnly'=>true, 'CanRequestChanges'=>false];
            return $person;
        };
        $merge = fn ($rows, $kind) => $rows->map(fn ($row) => $this->assignment($row, $kind) + $person($row, $kind)
            + ($kind === 'staff' ? ['Bezeichnung' => $functions->get($row->staff_function_id)?->name ?? '',
                'IsTrainer' => MemberTeamAccessService::trainerFunction($functions->get($row->staff_function_id))] : []))->all();
        $registrations = $this->registrationRows($actor,$tenant,$team);
        return ['Team' => $this->teamDto($actor, $tenant, $team), 'Staff' => $merge($staff, 'staff'), 'Players' => $merge($players, 'players'),
            'Trainings' => $this->trainingsForAuthorizedTeam($tenant, $id), 'Registrations' => $registrations, 'Members' => $members->values()->all()];
    }

    private function privateRegistrations(Account $actor,Tenant $tenant): bool
    {
        return app(ModulePermissionService::class)->allows($actor,$tenant,'registrations','view')
            || app(MemberAccessService::class)->membership($actor,$tenant)?->role==='technical_lead';
    }

    private function teamRegistrations(Account $actor,Tenant $tenant,int $team)
    {
        $access=app(MemberAccessService::class);
        if (!$this->privateRegistrations($actor,$tenant) && app(MemberTeamAccessService::class)->forAuthorizedTeam($actor,$tenant,$team)==='trainer') {
            return Registration::withoutGlobalScopes()->where('tenant_id',$tenant->id)->where('active',true)->where('team_season_id',$team);
        }
        return app(RegistrationReviewService::class)->visible($actor,$tenant)->where('team_season_id',$team);
    }

    private function basicRegistration(Registration $registration,object $team): array
    {
        return ['AnmeldungID'=>(int)$registration->id,'TeamSaisonID'=>(int)$team->id,'TeamSaisonLabel'=>$team->label,
            'Vorname'=>$registration->first_name,'Nachname'=>$registration->last_name,'Revision'=>(int)$registration->revision,
            'AnmeldungStatusText'=>$registration->status?->name,'StatusText'=>$registration->status?->name,
            'CanReadPrivateDetails'=>false,'CanEdit'=>false,'CanReview'=>false,'CanUpload'=>false];
    }

    private function registrationRows(Account $actor,Tenant $tenant,object $team): array
    {
        $private=$this->privateRegistrations($actor,$tenant);
        $records = $this->teamRegistrations($actor, $tenant, (int)$team->id)
            ->with(['status' => fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id)])->orderBy('id')->get();
        $catalogs = [];
        if ($private && $records->isNotEmpty()) {
            foreach (['countries', 'genders', 'billing_recipients'] as $table) { $catalogs[$table] = $this->table($tenant, $table)->pluck('name', 'id'); }
        }
        return $records->map(function ($r) use ($team, $catalogs, $private) {
            if (!$private) return $this->basicRegistration($r,$team);
            $v = app(RegistrationDataService::class)->values($r);
            $dto = ['AnmeldungID' => (int) $r->id, 'TeamSaisonID' => (int) $team->id, 'TeamSaisonLabel' => $team->label,
                'Vorname' => $r->first_name, 'Nachname' => $r->last_name, 'Email' => $r->email, 'Ort' => $r->city,
                'Geburtsdatum' => $v['birth_date'] ?? null, 'Revision' => $r->revision, 'StatusText' => $r->status?->name, 'CanReadPrivateDetails' => true,
                'LandText' => $catalogs['countries']->get($v['country_id'] ?? 0), 'NationalitaetText' => $catalogs['countries']->get($v['nationality_id'] ?? 0),
                'GeschlechtText' => $catalogs['genders']->get($v['gender_id'] ?? 0), 'RechnungAnText' => $catalogs['billing_recipients']->get($v['billing_recipient_id'] ?? 0),
                'EingereichtAm' => $r->submitted_at?->toIso8601String(), 'GeprueftAm' => $r->reviewed_at?->toIso8601String(), 'AkzeptiertAm' => $r->accepted_at?->toIso8601String()];
            foreach (['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'mobile' => 'Mobile', 'phone_private' => 'TelefonPrivat',
                'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter', 'mother_mobile' => 'MobileMutter', 'father_name' => 'Vater',
                'father_email' => 'EmailVater', 'father_mobile' => 'MobileVater', 'birth_place' => 'Geburtsort', 'notes' => 'Bemerkung'] as $from => $to) { $dto[$to] = $v[$from] ?? null; }
            return $dto;
        })->all();
    }

    public function registrations(Account $actor,Tenant $tenant,int $team): array
    {
        return $this->registrationRows($actor,$tenant,$this->team($actor,$tenant,$team));
    }

    public function registration(Account $actor,Tenant $tenant,int $team,int $registration): array
    {
        $resolved=$this->team($actor,$tenant,$team);
        $row=$this->teamRegistrations($actor,$tenant,$team)->with(['status'=>fn($q)=>$q->withoutGlobalScopes()->where('tenant_id',$tenant->id)])
            ->whereKey($registration)->firstOrFail();
        if (!$this->privateRegistrations($actor,$tenant)) return $this->basicRegistration($row,$resolved);
        abort_unless(config('clubunio-mobile.native_registrations_enabled',false),503);
        return app(MobileRegistrationData::class)->details($actor,$tenant,$registration)+['CanReadPrivateDetails'=>true];
    }

    public function trainings(Account $actor, Tenant $tenant, int $id): array
    {
        $this->team($actor, $tenant, $id);
        return $this->trainingsForAuthorizedTeam($tenant, $id);
    }

    /** Internal projection only: both callers first resolve the same fresh team scope. */
    private function trainingsForAuthorizedTeam(Tenant $tenant, int $id): array
    {
        $flag = app(SignageService::class)->trainingVariant($tenant);
        $options = $this->table($tenant, 'training_options')->where('active', true)->pluck('name', 'id');
        $slots = $this->table($tenant, 'training_slots')->where('active', true)->where('team_season_id', $id)->where($flag, true)
            ->orderBy('weekday_id')->orderBy('starts_at')->orderBy('id')->get();
        $areaIds = $slots->pluck('area_id')->filter()->unique();
        $areas = $areaIds->isEmpty() ? collect() : $this->table($tenant, 'pitch_areas')->whereIn('id', $areaIds)->get()->keyBy('id');
        return $slots->map(function ($r) use ($id, $options, $areas) {
            $area = $areas->get($r->area_id ?? null);
            if ($area && (int) $area->field_id !== (int) $r->field_id) $area = null;
            return [
                'TrainingID' => (int) $r->id, 'TeamSaisonID' => (int) $id, 'Wochentag' => $options->get($r->weekday_id, ''),
                'ZeitVon' => $r->starts_at, 'ZeitBis' => $r->ends_at, 'SommerWinter' => $options->get($r->period_id, ''),
                'Feld' => $options->get($r->field_id, ''), 'Halle' => $r->hall,
                'field_id' => $r->field_id ? (int) $r->field_id : null, 'area_id' => $area ? (int) $area->id : null,
                'area_name' => $area ? app(\App\ClubUnio\Services\PitchWeeklyAreas::class)->label($area) : null,
                'trainingAll' => (bool) $r->all_fields, 'trainingOhneHauptplatz' => (bool) $r->without_main, 'trainingNurKunstrasen' => (bool) $r->synthetic_only];
        })->all();
    }
}
