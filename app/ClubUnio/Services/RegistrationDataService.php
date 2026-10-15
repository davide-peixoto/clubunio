<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationDataService
{
    public const CORE = ['first_name', 'last_name', 'email', 'city', 'birth_date'];

    public const DETAILS = ['street', 'house_number', 'postal_code', 'country_id', 'nationality_id', 'gender_id',
        'mobile', 'phone_private', 'mother_name', 'mother_email', 'mother_mobile', 'father_name', 'father_email',
        'father_mobile', 'billing_recipient_id', 'notes', 'birth_place', 'previous_club', 'previous_club_country_type', 'has_previous_club'];

    public function values(Registration $r): array
    {
        $data = $r->applicant_encrypted ? json_decode(Crypt::decryptString($r->applicant_encrypted), true, 512, JSON_THROW_ON_ERROR) : [];

        return array_replace($data, $r->only(self::CORE), ['birth_date' => $r->birth_date?->format('Y-m-d')]);
    }

    public function formValues(Registration $r): array
    {
        return array_intersect_key($this->values($r), array_flip([...self::CORE, ...self::DETAILS])) + ['revision' => $r->revision];
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): Registration
    {
        abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);

        return $this->persist($actor, $tenant, $id, $input);
    }

    public function submitPublic(Tenant $tenant, array $input, string $requestId): Registration
    {
        app(PublicWebService::class)->authorize($tenant, 'registration');
        abort_unless(Str::isUuid($requestId), 422);

        return DB::connection('clubunio')->transaction(function () use ($tenant, $input, $requestId) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = DB::connection('clubunio')->table('clubora.public_registration_submissions')->where('request_id', $requestId)->where('tenant_id', $tenant->id)->first();
            if ($existing) {
                return Registration::where('tenant_id', $tenant->id)->findOrFail($existing->registration_id);
            }
            $r = $this->persist(null, $tenant, null, $input);
            DB::connection('clubunio')->table('clubora.public_registration_submissions')->insert(['request_id' => $requestId,
                'tenant_id' => $tenant->id, 'registration_id' => $r->id]);

            return $r;
        });
    }

    private function persist(?Account $actor, Tenant $tenant, ?int $id, array $input): Registration
    {
        if (array_diff(array_keys($input), [...self::CORE, ...self::DETAILS, 'revision'])) {
            throw ValidationException::withMessages(['first_name' => 'Nicht erlaubte Eingabefelder.']);
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($actor) {
                abort_unless($actor->fresh()?->canAccessTenant($tenant), 403);
            } else {
                app(PublicWebService::class)->authorize($tenant, 'registration');
                abort_unless($id === null, 403);
            }
            $r = $id ? Registration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id) : new Registration;
            if ($id && (int) ($input['revision'] ?? 0) !== (int) $r->revision) {
                throw ValidationException::withMessages(['revision' => 'Die Anmeldung wurde inzwischen geändert. Bitte erneut öffnen.']);
            }
            if ($id && $r->status?->workflow_key === 'accepted') {
                throw ValidationException::withMessages(['first_name' => 'Akzeptierte Anmeldungen bleiben unverändert; bitte das Mitglied bearbeiten.']);
            }
            $old = $id ? $this->values($r) : [];
            $values = array_replace($old, array_diff_key($input, ['revision' => true]));
            $rules = ['first_name' => 'required|string|max:255', 'last_name' => 'required|string|max:255',
                'email' => 'required|email:rfc|max:254', 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today',
                'city' => 'required|string|max:255', 'street' => 'required|string|max:255', 'postal_code' => 'required|string|max:20',
                'house_number' => 'nullable|string|max:20', 'notes' => 'nullable|string|max:10000',
                'birth_place' => 'nullable|string|max:255', 'previous_club' => 'nullable|string|max:255',
                'previous_club_country_type' => 'nullable|string|max:40', 'has_previous_club' => 'nullable|string|max:20'];
            foreach (['mobile', 'phone_private', 'mother_mobile', 'father_mobile'] as $field) {
                $rules[$field] = 'nullable|string|max:50';
            }
            foreach (['mother_name', 'father_name'] as $field) {
                $rules[$field] = 'nullable|string|max:255';
            }
            foreach (['mother_email', 'father_email'] as $field) {
                $rules[$field] = 'nullable|email:rfc|max:254';
            }
            foreach (['country_id' => 'countries', 'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'] as $field => $table) {
                $rules[$field] = ['required', 'integer', Rule::exists('clubunio.clubora.'.$table, 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->where('active', true)->orWhere('id', $old[$field] ?? -1)))];
            }
            $valid = Validator::make($values, $rules)->validate();
            $emailField = DB::connection('clubunio')->table('clubora.billing_recipients')->where('tenant_id', $tenant->id)->where('id', $valid['billing_recipient_id'])->value('email_field');
            if (empty($valid[$emailField])) {
                throw ValidationException::withMessages([$emailField => 'Für den Rechnungsempfänger fehlt eine E-Mail-Adresse.']);
            }
            $details = array_intersect_key($valid, array_flip(self::DETAILS));
            if (isset($old['ahv'])) {
                $details['ahv'] = $old['ahv'];
            }
            $r->forceFill(array_intersect_key($valid, array_flip(self::CORE)));
            if (! $id) {
                $status = DB::connection('clubunio')->table('clubora.registration_statuses')->where('tenant_id', $tenant->id)->where('active', true)->where('workflow_key', 'submitted')->value('id');
                if (! $status) {
                    throw ValidationException::withMessages(['first_name' => 'Der initiale Anmeldestatus ist nicht konfiguriert.']);
                }
                $r->forceFill(['tenant_id' => $tenant->id, 'status_id' => $status, 'submitted_at' => now(), 'active' => true]);
            }
            $r->forceFill(['applicant_encrypted' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)), 'details_complete' => true,
                'revision' => $id ? $r->revision + 1 : 1])->save();
            if ($actor) {
                DB::connection('clubunio')->table('clubora.object_changes')->insert([
                    'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'registrations', 'object_id' => $r->id,
                    'before_value' => null, 'after_value' => json_encode(['revision' => $r->revision, 'operation' => $id ? 'applicant_updated' : 'created',
                        'fields' => array_keys($valid)], JSON_THROW_ON_ERROR),
                ]);
            }
            if (! $id) {
                app(RegistrationMessageService::class)->record($tenant, $r, 'registration.created');
                app(RegistrationMessageService::class)->record($tenant, $r, 'registration.received');
            }

            return $r;
        });
    }
}
