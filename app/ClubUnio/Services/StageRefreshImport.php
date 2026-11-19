<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use LogicException;

final class StageRefreshImport
{
    public function run(Connection $db, Tenant $tenant): array
    {
        if (! StageMigrationTarget::isStage($db) || ! $db->transactionLevel()) {
            throw new LogicException('Expected an isolated Stage build transaction.');
        }
        $table = fn ($name) => $db->table('clubunio.'.$name)->where('tenant_id', $tenant->id);
        $source = $db->table('legacy_prod.iniParameter')->whereNull('sdeletedAt')->sole();
        $period = $table('training_options')->where('category', 'period')->where('code', trim($source->SommerWinter))
            ->where('active', true)->sole();
        $table('tenant_settings')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'area' => 'general', 'key' => ActiveTrainingPlan::KEY],
            ['value_type' => 'integer', 'is_secret' => false, 'enabled' => true,
                'encrypted_value' => Crypt::encryptString((string) $period->id)],
        );

        $accounts = $table('tenant_accounts')->join('clubunio.accounts as a', 'a.id', '=', 'account_id')
            ->get(['a.username', 'a.id'])->mapWithKeys(fn ($row) => [mb_strtolower(trim($row->username)) => $row->id]);
        $statuses = $table('member_change_statuses')->pluck('id', 'code');
        $nationalities = $table('countries')->pluck('id', 'iso3');
        $recipients = $table('billing_recipients')->pluck('id', 'code');
        $fields = ['Email' => 'email', 'EmailMutter' => 'mother_email', 'EmailVater' => 'father_email',
            'HausNr' => 'house_number', 'Mobile' => 'mobile', 'Mutter' => 'mother_name',
            'Nationalitaet' => 'nationality_id', 'Ort' => 'city', 'PLZ' => 'postal_code',
            'RechnungAn' => 'billing_recipient_id', 'Strasse' => 'street', 'TelefonPrivat' => 'phone_private'];
        $map = function (string $json) use ($fields, $nationalities, $recipients): array {
            $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $mapped = [];
            foreach ($values as $key => $value) {
                $field = $fields[$key] ?? throw new LogicException('Unmapped member change field.');
                $lookup = match ($field) { 'nationality_id' => $nationalities, 'billing_recipient_id' => $recipients, default => null };
                $mapped[$field] = $lookup && $value !== null && trim((string) $value) !== ''
                    ? ($lookup[trim((string) $value)] ?? throw new LogicException('Unmapped member change lookup.'))
                    : ($value === '' ? null : $value);
            }
            return $mapped;
        };
        $account = fn ($username) => $accounts[mb_strtolower(trim($username))] ?? throw new LogicException('Unmapped member change account.');
        $counts = [];
        foreach ($db->table('legacy_prod.MitgliederAenderungsantraege')->whereNull('sdeletedAt')->orderBy('AenderungsantragID')->cursor() as $row) {
            $member = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('legacy_id', $row->MitgliederID)->sole();
            $state = match ($row->Status) { 'OFFEN' => 'pending', 'GENEHMIGT' => 'approved', 'ABGELEHNT' => 'rejected',
                default => throw new LogicException('Unmapped member change status.') };
            $before = $map($row->AltwerteJson);
            $changes = $map($row->NeuwerteJson);
            if (array_diff_key($changes, $before)) {
                throw new LogicException('Missing original member change value.');
            }
            $baseline = app(MemberChangeService::class)->values($member);
            $matches = true;
            foreach ($before as $key => $value) {
                $matches = $matches && (string) $value === (string) ($baseline[$key] ?? null);
            }
            $table('member_change_requests')->insert([
                'tenant_id' => $tenant->id, 'member_id' => $member->id,
                'requested_by' => $account($row->AntragstellerUsername),
                'reviewed_by' => $row->EntscheidVon ? $account($row->EntscheidVon) : null,
                'status_id' => $statuses[$state], 'state' => $state,
                'member_version' => $matches ? MemberService::version($member) : hash('sha256', 'legacy-stale:'.$row->DatensatzVersion),
                'snapshot_encrypted' => Crypt::encryptString(json_encode([
                    'before' => $before, 'changes' => $changes, 'team_id' => null,
                    'legacy' => ['id' => $row->AenderungsantragID, 'applied_at' => $row->AppliedAt, 'apply_error' => $row->ApplyError],
                ], JSON_THROW_ON_ERROR)),
                'created_at' => $row->CreatedAt, 'updated_at' => $row->UpdatedAt ?? $row->CreatedAt,
                'reviewed_at' => $row->EntscheidAm, 'decision_note' => $row->EntscheidBegruendung,
            ]);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        $projection = app(PitchPlanningProjection::class);
        $today = now('Europe/Zurich')->toDateString();
        $entries = $projection->entries($tenant, $today, now('Europe/Zurich')->addWeek()->toDateString());
        $training = count(array_filter($entries, fn ($entry) => ($entry['source'] ?? null) === 'training'));
        if (! $projection->ready() || (int) app(ActiveTrainingPlan::class)->period($tenant)?->id !== (int) $period->id || $training === 0) {
            throw new LogicException('Stage pitch occupancy acceptance failed.');
        }
        return ['change_requests' => $counts, 'period' => $period->code, 'pitch_training_next_week' => $training];
    }
}
