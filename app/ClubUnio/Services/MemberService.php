<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberService
{
    private const LOOKUPS = ['status_id' => 'member_statuses', 'type_id' => 'member_types', 'country_id' => 'countries',
        'nationality_id' => 'countries', 'gender_id' => 'genders', 'billing_recipient_id' => 'billing_recipients'];

    public const FIELDS = ['first_name', 'last_name', 'company', 'email', 'city', 'birth_date', 'status_id', 'type_id',
        'street', 'house_number', 'postal_code', 'country_id', 'nationality_id', 'gender_id', 'phone_private', 'phone_business',
        'mobile', 'website', 'pass_number', 'exit_date', 'mother_name', 'mother_email', 'mother_mobile',
        'father_name', 'father_email', 'father_mobile', 'billing_recipient_id', 'honorary', 'free_membership', 'supporter', 'sponsor', 'spots'];

    public static function version(Member $member): string
    {
        return hash('sha256', json_encode($member->only(array_merge(self::FIELDS, ['archived_at'])), JSON_THROW_ON_ERROR));
    }

    public function create(Account $actor, Tenant $tenant, array $input): Member
    {
        return $this->save($actor, $tenant, null, $input);
    }

    public function update(Account $actor, Tenant $tenant, int $id, array $input): Member
    {
        return $this->save($actor, $tenant, $id, $input);
    }

    public function fromRegistration(Account $actor, Tenant $tenant, Registration $registration, ?Member $existing, int $typeId): Member
    {
        abort_unless(app(RegistrationReviewService::class)->canReview($actor, $tenant, $registration), 403);
        $values = app(RegistrationDataService::class)->values($registration);
        $status = MemberStatus::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('restores_membership', true)->get();
        if ($status->count() !== 1) {
            throw ValidationException::withMessages(['status_id' => 'Genau ein aktiver Mitgliedstatus für den Wiedereintritt muss konfiguriert sein.']);
        }
        $input = array_intersect_key($values, array_flip([...RegistrationDataService::CORE,
            ...array_intersect(RegistrationDataService::DETAILS, self::FIELDS)]));
        // Administrative/financial flags and pass number are not applicant fields.
        $input = array_replace($input, ['company' => $existing?->company, 'status_id' => $status->sole()->id, 'type_id' => $typeId, 'exit_date' => null]);
        if ($existing) {
            $input['_version'] = self::version($existing);
        }

        return $this->save($actor, $tenant, $existing?->id, $input, $registration);
    }

    private function save(Account $actor, Tenant $tenant, ?int $id, array $input, ?Registration $registration = null): Member
    {
        abort_unless($actor->canAccessTenant($tenant) || ($registration && app(RegistrationReviewService::class)->canReview($actor, $tenant, $registration)), 403);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            if ($id === null) {
                // Serialize per-club number allocation, never reuse a legacy ID.
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $member = new Member;
                $member->forceFill(['tenant_id' => $tenant->id, 'member_number' => 1 + (int) Member::where('tenant_id', $tenant->id)->max('member_number')]);
            } else {
                $member = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereKey($id)->lockForUpdate()->firstOrFail();
            }
            $rules = [
                '_version' => $id ? 'required|string|size:64' : 'prohibited',
                'first_name' => 'present|nullable|required_without:company|string|max:255',
                'last_name' => 'present|nullable|required_without:company|string|max:255',
                'company' => 'present|nullable|string|max:255', 'email' => 'nullable|email:rfc|max:254',
                'city' => 'nullable|string|max:255', 'birth_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
                'exit_date' => 'nullable|date_format:Y-m-d', 'website' => 'nullable|url:http,https|max:255',
                'pass_number' => 'nullable|string|max:30|regex:/^[0-9]+$/',
                'tenant_id' => 'prohibited', 'legacy_id' => 'prohibited', 'id' => 'prohibited', 'member_number' => 'prohibited',
                'archived_at' => 'prohibited', 'bexio_contact_id' => 'prohibited', 'ahv_encrypted' => 'prohibited',
            ];
            foreach (['street', 'mother_name', 'father_name'] as $field) {
                $rules[$field] = 'nullable|string|max:255';
            }
            foreach (['house_number', 'postal_code'] as $field) {
                $rules[$field] = 'nullable|string|max:20';
            }
            foreach (['phone_private', 'phone_business', 'mobile', 'mother_mobile', 'father_mobile'] as $field) {
                $rules[$field] = 'nullable|string|max:50';
            }
            foreach (['mother_email', 'father_email'] as $field) {
                $rules[$field] = 'nullable|email:rfc|max:254';
            }
            foreach (['honorary', 'free_membership', 'supporter', 'sponsor', 'spots'] as $field) {
                $rules[$field] = 'boolean';
            }
            foreach (self::FIELDS as $field) {
                if (isset($input[$field]) && is_string($input[$field])) {
                    $input[$field] = trim($input[$field]);
                    if ($input[$field] === '') {
                        $input[$field] = null;
                    }
                }
            }
            // Validate the resulting record so recipient checks cannot be bypassed.
            $values = $member->only(self::FIELDS);
            foreach (['birth_date', 'exit_date'] as $date) {
                $values[$date] = $member->$date?->format('Y-m-d');
            }
            $values = array_merge($values, $input);
            foreach (['honorary', 'free_membership', 'supporter', 'sponsor', 'spots'] as $field) {
                $values[$field] ??= false;
            }
            $lookups = $this->lookupRows($tenant, $values);
            foreach (self::LOOKUPS as $field => $table) {
                $required = $id === null && in_array($field, ['status_id', 'type_id', 'billing_recipient_id'], true);
                $allowed = $lookups[$table]->filter(fn ($row) => $row->active || (int) $row->id === (int) ($member->$field ?? -1))->keys()->all();
                $rules[$field] = [$required ? 'required' : 'nullable', 'integer', Rule::in($allowed)];
            }
            $data = Validator::make($values, $rules, [], [
                'first_name' => 'Vorname', 'last_name' => 'Nachname', 'company' => 'Firma', 'email' => 'E-Mail',
                'city' => 'Ort', 'birth_date' => 'Geburtsdatum', 'status_id' => 'Status', 'type_id' => 'Mitgliederart',
                'billing_recipient_id' => 'Rechnung an', 'exit_date' => 'Austrittsdatum',
            ])->validate();
            if ($id && ! hash_equals(self::version($member), $data['_version'])) {
                throw ValidationException::withMessages(['company' => 'Dieser Datensatz wurde inzwischen geändert. Bitte schliessen und erneut öffnen.']);
            }
            if ($data['billing_recipient_id'] ?? null) {
                $field = $lookups['billing_recipients']->get($data['billing_recipient_id'])->email_field;
                if (empty($data[$field])) {
                    throw ValidationException::withMessages([$field => 'Für den gewählten Rechnungsempfänger wird eine E-Mail-Adresse benötigt.']);
                }
            }
            $status = $lookups['member_statuses']->get($data['status_id'] ?? null);
            if ((string) $member->status_id !== (string) ($data['status_id'] ?? null)) {
                if ($status?->restores_membership) {
                    $data['exit_date'] = null;
                }
                if ($status?->ends_membership && empty($data['exit_date'])) {
                    $data['exit_date'] = now('Europe/Zurich')->toDateString();
                }
            }
            if ($status?->ends_membership && empty($data['exit_date'])) {
                throw ValidationException::withMessages(['exit_date' => 'Für diesen Status ist ein Austrittsdatum erforderlich.']);
            }
            $data = array_intersect_key($data, array_flip(self::FIELDS));
            $before = $member->exists ? $member->only(self::FIELDS) : null;
            $member->forceFill($data);
            if (! $member->exists || $member->isDirty()) {
                $member->save();
                $this->audit($actor, $tenant, $member, $before, $member->only(self::FIELDS));
            }

            return $member;
        });
    }

    private function lookupRows(Tenant $tenant, array $values): array
    {
        // Fresh, bounded lookup reads inside the write transaction; never cache form values.
        $rows = [];
        foreach (array_unique(self::LOOKUPS) as $table) {
            $ids = [];
            foreach (self::LOOKUPS as $field => $lookupTable) {
                $value = $values[$field] ?? null;
                if ($lookupTable === $table && $value !== null && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                    $ids[] = (int) $value;
                }
            }
            $columns = match ($table) {
                'member_statuses' => ['id', 'active', 'restores_membership', 'ends_membership'],
                'billing_recipients' => ['id', 'active', 'email_field'],
                default => ['id', 'active'],
            };
            $rows[$table] = $ids === [] ? collect() : DB::connection('clubunio')->table('clubora.'.$table)
                ->where('tenant_id', $tenant->id)->whereIn('id', array_unique($ids))->get($columns)->keyBy('id');
        }

        return $rows;
    }

    public function archive(Account $actor, Tenant $tenant, int $id, string $version): void
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $version) {
            $member = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! hash_equals(self::version($member), $version)) {
                throw ValidationException::withMessages(['version' => 'Datensatz geändert. Bitte neu laden.']);
            }
            $member->forceFill(['archived_at' => now()])->save();
            $this->audit($actor, $tenant, $member, ['archived_at' => null], ['archived_at' => $member->archived_at]);
        });
    }

    private function audit(Account $actor, Tenant $tenant, Member $member, ?array $before, array $after): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert([
            'tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'members', 'object_id' => $member->id,
            'before_value' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_value' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }
}
