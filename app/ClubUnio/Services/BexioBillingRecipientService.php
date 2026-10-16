<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BexioBillingRecipientService
{
    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['contact_id' => $message]);
    }

    public function identity(Member $member): array
    {
        $recipient = $member->billingRecipient;
        if (! $recipient || ! $recipient->active || $recipient->tenant_id != $member->tenant_id) {
            $this->invalid('Aktiver Rechnungsempfänger fehlt.');
        }
        $field = $recipient->email_field;
        $name = match ($field) {
            'email' => trim($member->company ?: $member->first_name.' '.$member->last_name),
            'mother_email' => trim((string) $member->mother_name),
            'father_email' => trim((string) $member->father_name),
            default => $this->invalid('Unbekannte Adressquelle des Rechnungsempfängers.'),
        };

        return ['recipient_id' => $recipient->id, 'field' => $field, 'name' => $name, 'email' => trim((string) $member->$field)];
    }

    private function identityHash(Member $member): string
    {
        return hash_hmac('sha256', json_encode($this->identity($member), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function version(Member $member): string
    {
        return hash('sha256', json_encode([$member->getAttributes(), $member->billingRecipient?->getAttributes()], JSON_THROW_ON_ERROR));
    }

    public function resolve(Member $member): int
    {
        $identity = $this->identity($member);
        if ($identity['field'] === 'email') {
            return $this->contactId($member->bexio_contact_id);
        }
        if (! $member->billing_bexio_identity_hash || ! hash_equals($member->billing_bexio_identity_hash, $this->identityHash($member))) {
            $this->invalid('Bexio-Zuordnung des Rechnungsempfängers fehlt oder ist veraltet. Im Mitgliederdossier neu bestätigen.');
        }

        return $this->contactId($member->billing_bexio_contact_id);
    }

    private function contactId(mixed $value): int
    {
        if (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 2147483647) {
            $this->invalid('Gültige Bexio-Kontakt-ID erforderlich.');
        }

        return (int) $value;
    }

    public function save(Account $actor, Tenant $tenant, int $id, array $input): void
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'bexio', 'update');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'bexio', 'update');
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($id);
            Validator::make($input, ['version' => 'required|string|size:64', 'contact_id' => 'present|nullable|integer|min:1|max:2147483647', 'confirmed' => 'accepted'])->validate();
            if (! hash_equals($this->version($member), $input['version'])) {
                $this->invalid('Mitglied oder Zuordnung wurde geändert. Bitte neu öffnen.');
            }
            $contact = blank($input['contact_id']) ? null : $this->contactId($input['contact_id']);
            $hash = null;
            if ($contact !== null) {
                $identity = $this->identity($member);
                if ($identity['field'] === 'email') {
                    $this->invalid('Beim eigenen Rechnungsempfänger gilt der bestehende Mitgliedskontakt.');
                }
                if ($identity['name'] === '' || ! filter_var($identity['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->invalid('Name und gültige E-Mail des Rechnungsempfängers zuerst im Mitglied ergänzen.');
                }
                $hash = $this->identityHash($member);
            }
            $before = $member->only(['billing_bexio_contact_id', 'billing_bexio_revision']);
            $member->forceFill(['billing_bexio_contact_id' => $contact, 'billing_bexio_identity_hash' => $hash,
                'billing_bexio_revision' => $member->billing_bexio_revision + 1])->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'members', 'object_id' => $id, 'before_value' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_value' => json_encode($member->only(['billing_bexio_contact_id', 'billing_bexio_revision']), JSON_THROW_ON_ERROR)]);
        });
    }
}
