<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Member, Tenant, TeamPlayer, TeamStaff};
use App\ClubUnio\Services\{FamilyService, MemberAccessService, ModulePermissionService, RegistrationDataService, RegistrationReviewService, SignageService};
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
        $total = (clone $q)->count();
        $capabilities = $this->capabilities($actor, $tenant);
        return ['Teams' => $q->orderBy('ts.team_id')->orderBy('ts.id')->offset($d['start'] ?? 0)->limit($d['recperpage'] ?? 100)->get()
            ->map(fn ($row) => $this->teamDto($actor, $tenant, $row, $capabilities))->all(), 'totalRecordCount' => $total];
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
        $visible = array_fill_keys($access->visibleMembers($actor, $tenant)->whereIn('clubora.members.id', $ids)->pluck('id')->all(), true);
        $own = array_fill_keys($access->represented($actor, $tenant)->whereIn('clubora.members.id', $ids)->pluck('id')->all(), true);
        $full = app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view');
        $members = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name',
            'email', 'mobile', 'city', 'birth_date', 'street', 'house_number', 'postal_code', 'phone_private', 'phone_business',
            'mother_name', 'mother_email', 'mother_mobile', 'father_name', 'father_email', 'father_mobile'])->mapWithKeys(function ($m) use ($visible, $own, $full) {
            $d = ['MitgliederID' => (int) $m->id, 'Vorname' => $m->first_name, 'Nachname' => $m->last_name,
                'CanOpenDossier' => isset($visible[$m->id]) || $full];
            if (isset($visible[$m->id]) || $full) {
                $d += ['Email' => $m->email, 'Mobile' => $m->mobile, 'Ort' => $m->city];
            }
            if (isset($own[$m->id]) || $full) {
                foreach (['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'phone_private' => 'TelefonPrivat',
                    'phone_business' => 'TelefonGeschaeft', 'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter', 'mother_mobile' => 'MobileMutter',
                    'father_name' => 'Vater', 'father_email' => 'EmailVater', 'father_mobile' => 'MobileVater'] as $from => $to) { $d[$to] = $m->$from; }
                $d['Geburtsdatum'] = $m->birth_date?->format('Y-m-d');
            }
            return [(int) $m->id => $d];
        });
        $functions = $this->table($tenant, 'staff_functions')->get(['id', 'code', 'name'])->keyBy('id');
        $merge = fn ($rows, $kind) => $rows->map(fn ($row) => $this->assignment($row, $kind) + ($members->get($row->member_id) ?? [])
            + ($kind === 'staff' ? ['Bezeichnung' => $functions->get($row->staff_function_id)?->name ?? '',
                'IsTrainer' => strtoupper($functions->get($row->staff_function_id)?->code ?? '') === 'TRAINER'
                    || mb_strtolower(trim($functions->get($row->staff_function_id)?->name ?? '')) === 'trainer'] : []))->all();
        $records = app(RegistrationReviewService::class)->visible($actor, $tenant)->where('team_season_id', $id)
            ->with(['status' => fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenant->id)])->orderBy('id')->get();
        $catalogs = [];
        if ($records->isNotEmpty()) {
            foreach (['countries', 'genders', 'billing_recipients'] as $table) { $catalogs[$table] = $this->table($tenant, $table)->pluck('name', 'id'); }
        }
        $registrations = $records->map(function ($r) use ($team, $catalogs) {
            $v = app(RegistrationDataService::class)->values($r);
            $dto = ['AnmeldungID' => (int) $r->id, 'TeamSaisonID' => (int) $team->id, 'TeamSaisonLabel' => $team->label,
                'Vorname' => $r->first_name, 'Nachname' => $r->last_name, 'Email' => $r->email, 'Ort' => $r->city,
                'Geburtsdatum' => $v['birth_date'] ?? null, 'Revision' => $r->revision, 'StatusText' => $r->status?->name,
                'LandText' => $catalogs['countries']->get($v['country_id'] ?? 0), 'NationalitaetText' => $catalogs['countries']->get($v['nationality_id'] ?? 0),
                'GeschlechtText' => $catalogs['genders']->get($v['gender_id'] ?? 0), 'RechnungAnText' => $catalogs['billing_recipients']->get($v['billing_recipient_id'] ?? 0),
                'EingereichtAm' => $r->submitted_at?->toIso8601String(), 'GeprueftAm' => $r->reviewed_at?->toIso8601String(), 'AkzeptiertAm' => $r->accepted_at?->toIso8601String()];
            foreach (['street' => 'Strasse', 'house_number' => 'HausNr', 'postal_code' => 'PLZ', 'mobile' => 'Mobile', 'phone_private' => 'TelefonPrivat',
                'mother_name' => 'Mutter', 'mother_email' => 'EmailMutter', 'mother_mobile' => 'MobileMutter', 'father_name' => 'Vater',
                'father_email' => 'EmailVater', 'father_mobile' => 'MobileVater', 'birth_place' => 'Geburtsort', 'notes' => 'Bemerkung'] as $from => $to) { $dto[$to] = $v[$from] ?? null; }
            return $dto;
        })->all();
        return ['Team' => $this->teamDto($actor, $tenant, $team), 'Staff' => $merge($staff, 'staff'), 'Players' => $merge($players, 'players'),
            'Trainings' => $this->trainings($actor, $tenant, $id), 'Registrations' => $registrations, 'Members' => $members->values()->all()];
    }

    public function trainings(Account $actor, Tenant $tenant, int $id): array
    {
        $this->team($actor, $tenant, $id);
        $flag = app(SignageService::class)->trainingVariant($tenant);
        $options = $this->table($tenant, 'training_options')->where('active', true)->pluck('name', 'id');
        return $this->table($tenant, 'training_slots')->where('active', true)->where('team_season_id', $id)->where($flag, true)
            ->orderBy('weekday_id')->orderBy('starts_at')->orderBy('id')->get()->map(fn ($r) => [
                'TrainingID' => (int) $r->id, 'TeamSaisonID' => (int) $id, 'Wochentag' => $options->get($r->weekday_id, ''),
                'ZeitVon' => $r->starts_at, 'ZeitBis' => $r->ends_at, 'SommerWinter' => $options->get($r->period_id, ''),
                'Feld' => $options->get($r->field_id, ''), 'Halle' => $r->hall,
                'trainingAll' => (bool) $r->all_fields, 'trainingOhneHauptplatz' => (bool) $r->without_main, 'trainingNurKunstrasen' => (bool) $r->synthetic_only])->all();
    }
}
