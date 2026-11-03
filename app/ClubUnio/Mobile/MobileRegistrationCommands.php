<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ModulePermissionService, RegistrationDataService, RegistrationReviewService};
use Illuminate\Support\Facades\{DB, Validator};

final class MobileRegistrationCommands
{
    public function values(Tenant $tenant, array $input): array
    {
        abort_if(array_diff(array_keys($input), array_keys(MobileRegistrationData::FIELDS)), 422, 'Nicht erlaubte Anmeldefelder.');
        $values = [];
        foreach ($input as $field => $value) {
            $target = MobileRegistrationData::FIELDS[$field];
            if (in_array($field, ['Land', 'Nationalitaet'], true)) {
                $code = Validator::make(['code' => $value], ['code' => 'required|string|max:3'])->validate()['code'];
                $id = DB::connection('clubunio')->table('clubora.countries')->where('tenant_id', $tenant->id)
                    ->where(fn ($q) => $q->whereRaw('upper(code) = ?', [strtoupper($code)])->orWhereRaw('upper(iso3) = ?', [strtoupper($code)]))->value('id');
                abort_unless($id, 422, 'Laenderauswahl ist nicht mehr gueltig.'); $value = (int) $id;
            }
            $values[$target] = $value;
        }
        return $values;
    }
    public function intake(Account $actor, Tenant $tenant, ?int $id, array $input): array
    {
        abort_unless(DB::connection('clubunio')->transactionLevel() > 0, 500);
        $service = app(MobileRegistrationData::class); $service->authorizeReader($actor, $tenant);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'registrations', $id ? 'update' : 'create');
        abort_if(array_diff(array_keys($input), ['RequestKey', 'Revision', 'Values']), 422);
        $d = Validator::make($input, ['Values' => 'required|array', 'Revision' => $id ? 'required|integer|min:1' : 'prohibited', 'RequestKey' => $id ? 'prohibited' : 'required|uuid'])->validate();
        $values = $this->values($tenant, $d['Values']);
        if ($id) {
            $service->registration($actor, $tenant, $id);
            app(RegistrationDataService::class)->save($actor, $tenant, $id, $values + ['revision' => $d['Revision']]);
        } else {
            ksort($values); $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $table = DB::connection('clubunio')->table('clubora.mobile_registration_requests')->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
            if ($existing = (clone $table)->where('request_key', $d['RequestKey'])->first()) {
                abort_unless(hash_equals($existing->payload_sha256, $hash), 409, 'Dieser Erfassungsschluessel gehoert zu anderen Eingaben.');
                return $service->details($actor, $tenant, (int) $existing->registration_id);
            }
            $r = app(RegistrationDataService::class)->save($actor, $tenant, null, $values);
            DB::connection('clubunio')->table('clubora.mobile_registration_requests')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                'request_key' => $d['RequestKey'], 'payload_sha256' => $hash, 'registration_id' => $r->id]);
            $id = (int) $r->id;
        }
        return $service->details($actor, $tenant, $id);
    }
    public function review(Account $actor, Tenant $tenant, int $id, array $input): array
    {
        $data = app(MobileRegistrationData::class); $data->registration($actor, $tenant, $id);
        app(RegistrationReviewService::class)->review($actor, $tenant, $id, $input);
        return $data->details($actor, $tenant, $id);
    }
}
