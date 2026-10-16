<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SfvCompetitionService
{
    public function review(Account $actor, Tenant $tenant, int $matchId, ?int $target = null, bool $separate = false): array
    {
        try {
            return $this->preview($actor, $tenant, $matchId, $target, $separate);
        } catch (ValidationException $original) {
            try {
                $withdrawal = $this->previewWithdrawal($actor, $tenant, $matchId);
                if ($target && $target !== $withdrawal['target_id']) {
                    throw $original;
                }

                return $withdrawal;
            } catch (ValidationException) {
                throw $original;
            }
        }
    }

    public function previewWithdrawal(Account $actor, Tenant $tenant, int $matchId): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'view');
        $match = DB::connection('clubunio')->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)->where('id', $matchId)->first();
        abort_unless($match, 404);
        $records = Competition::where('tenant_id', $tenant->id)->where('association_match_id', $match->match_id)->get();
        $record = $records->first();
        if ($records->count() !== 1 || ! $record->active || $record->plays_on->toDateString() < now('Europe/Zurich')->toDateString()) {
            throw ValidationException::withMessages(['match' => 'Keine eindeutige aktive zukünftige Wettkampfverknüpfung für die Ausblendung.']);
        }
        $state = mb_strtolower(trim((string) $match->state));
        $cancelled = in_array($state, ['abgesagt', 'annulliert', 'abgebrochen', 'verschoben'], true);
        $located = trim((string) $match->location) !== '';
        $moved = $located && ! preg_match('/Tannenbrunn\s*(?:-\s*([123])\s*,\s*Sissach|,\s*Sissach\s*-\s*([123]))\s*$/iu', trim($match->location));
        if (! $cancelled && ! (in_array($state, ['angesetzt', 'neu angesetzt', 'noch nicht ausgetragen'], true) && $moved)) {
            throw ValidationException::withMessages(['match' => 'Kein eindeutiger SFV-Grund zur Ausblendung.']);
        }
        $before = $record->only(['plays_on', 'starts_at', 'host_name', 'guest_name', 'field_id', 'association_match_id', 'source', 'show_on_signage']);
        $before['plays_on'] = $record->plays_on->toDateString();
        $data = ['kind' => 'withdrawal', 'match_id' => $matchId, 'target_id' => $record->id, 'sfv_revision' => $match->revision, 'target_revision' => $record->revision,
            'before' => $before, 'after' => array_replace($before, ['show_on_signage' => false]), 'field' => $record->field?->name, 'previous_field' => $record->field?->name,
            'state' => $match->state, 'reason' => $cancelled ? 'SFV-Status: '.$match->state : 'SFV-Spielort: '.$match->location];
        $data['fingerprint'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }

    public function previewBatch(Account $actor, Tenant $tenant, string $from, string $to): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'view');
        Validator::make(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $matches = DB::connection('clubunio')->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)
            ->whereRaw("(starts_at AT TIME ZONE 'Europe/Zurich')::date BETWEEN ? AND ?", [$from, $to])->orderBy('starts_at')->orderBy('id')->limit(101)->get();
        if ($matches->count() > 100) {
            throw ValidationException::withMessages(['to' => 'Maximal 100 Spiele pro Prüfung. Bitte Zeitraum eingrenzen.']);
        }
        $entries = [];
        $issues = [];
        $unchanged = 0;
        foreach ($matches as $match) {
            try {
                $preview = $this->review($actor, $tenant, $match->id);
                if ($preview['before'] == $preview['after']) {
                    $unchanged++;
                } else {
                    $entries[] = $preview;
                }
            } catch (ValidationException $error) {
                $issues[] = ['title' => $match->title, 'message' => $error->getMessage()];
            }
        }

        return compact('entries', 'issues', 'unchanged');
    }

    public function applyBatch(Account $actor, Tenant $tenant, array $entries): int
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'sync');
        abort_if(count($entries) > 100, 422);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $entries) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            foreach ($entries as $entry) {
                $this->apply($actor, $tenant, $entry);
            }

            return count($entries);
        });
    }

    public function preview(Account $actor, Tenant $tenant, int $matchId, ?int $target = null, bool $separate = false): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'view');
        $db = DB::connection('clubunio');
        $match = $db->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)->where('id', $matchId)->first();
        abort_unless($match, 404);
        if (! $match->starts_at || ! $match->host_name || ! $match->guest_name) {
            throw ValidationException::withMessages(['match' => 'Datum oder Teamnamen fehlen. Bitte SFV-Daten neu abrufen.']);
        }
        $date = Time::parse($match->starts_at)->tz('Europe/Zurich');
        if ($date->toDateString() < now('Europe/Zurich')->toDateString()) {
            throw ValidationException::withMessages(['match' => 'Vergangene Spiele bleiben unverändert.']);
        }
        // Only a mapped venue qualifies for Signage, not merely the club being the home team.
        if (! preg_match('/Tannenbrunn\s*(?:-\s*([123])\s*,\s*Sissach|,\s*Sissach\s*-\s*([123]))\s*$/iu', trim((string) $match->location), $parts)) {
            throw ValidationException::withMessages(['match' => 'Kein eindeutig zugeordnetes Spielfeld in Tannenbrunn. Keine Übernahme.']);
        }
        $code = ['1' => 'H', '2' => 'K', '3' => 'T'][$parts[1] ?: ($parts[2] ?? '')] ?? null;
        $field = $db->table('clubora.training_options')->where('tenant_id', $tenant->id)->where('category', 'field')->where('code', $code)->where('active', true)->first();
        if (! $field) {
            throw ValidationException::withMessages(['match' => 'Die Spielfeldzuordnung fehlt oder ist inaktiv.']);
        }
        if (! in_array(mb_strtolower(trim((string) $match->state)), ['angesetzt', 'neu angesetzt', 'noch nicht ausgetragen'], true)) {
            throw ValidationException::withMessages(['match' => 'Dieses Spiel benötigt wegen seines SFV-Status eine manuelle Prüfung: '.$match->state]);
        }
        $base = Competition::where('tenant_id', $tenant->id);
        $linked = (clone $base)->where('association_match_id', $match->match_id)->get();
        if ($linked->count() > 1 || ($linked->isNotEmpty() && ! $linked->first()->active)) {
            throw ValidationException::withMessages(['match' => 'Mehrfache oder archivierte SFV-Verknüpfung. Bitte fachlich bereinigen.']);
        }
        $record = $linked->first();
        if ($target) {
            $chosen = (clone $base)->findOrFail($target);
            if (! $chosen->active || ($record && $record->id !== $chosen->id) || ($chosen->association_match_id && (int) $chosen->association_match_id !== (int) $match->match_id)) {
                throw ValidationException::withMessages(['target' => 'Dieser Wettkampf kann nicht mit dem Spiel verknüpft werden.']);
            }
            $record = $chosen;
        }
        if (! $record && ! $separate && (clone $base)->where('active', true)->whereNull('association_match_id')->whereDate('plays_on', $date->toDateString())->exists()) {
            throw ValidationException::withMessages(['target' => 'Es gibt manuelle Wettkämpfe an diesem Tag. Bitte den passenden Eintrag auswählen oder zuerst fachlich prüfen.']);
        }
        $after = ['plays_on' => $date->toDateString(), 'starts_at' => $date->format('H:i:s'), 'host_name' => $match->host_name, 'guest_name' => $match->guest_name, 'field_id' => $field->id,
            'association_match_id' => $match->match_id, 'source' => 'SFV'];
        $before = $record?->only(array_keys($after));
        if ($before) {
            $before['plays_on'] = Time::parse($before['plays_on'])->toDateString();
        }
        $data = ['match_id' => $matchId, 'target_id' => $record?->id, 'separate' => $separate, 'sfv_revision' => $match->revision, 'target_revision' => $record?->revision, 'before' => $before, 'after' => $after, 'field' => $field->name, 'state' => $match->state];
        $data['previous_field'] = $record?->field?->name;
        $data['fingerprint'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $data;
    }

    public function apply(Account $actor, Tenant $tenant, array $preview): int
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'sync');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $preview) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'competitions', 'sync');
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'signage', 'send');
            $fresh = ($preview['kind'] ?? '') === 'withdrawal'
                ? $this->previewWithdrawal($actor, $tenant, (int) $preview['match_id'])
                : $this->preview($actor, $tenant, (int) $preview['match_id'], $preview['target_id'], $preview['separate'] ?? false);
            if (! hash_equals($fresh['fingerprint'], (string) $preview['fingerprint'])) {
                throw ValidationException::withMessages(['match' => 'SFV-Daten oder Wettkampf wurden geändert. Bitte eine neue Vorschau erstellen.']);
            }
            $record = $fresh['target_id'] ? Competition::where('tenant_id', $tenant->id)->findOrFail($fresh['target_id']) : new Competition;
            if ($fresh['before'] == $fresh['after']) {
                return $record->id;
            }
            $data = $fresh['after'] + ['synced_at' => now('UTC')->format('Y-m-d H:i:s'), 'revision' => ($record->revision ?? 0) + 1];
            if (! $record->exists) {
                $data += ['tenant_id' => $tenant->id, 'active' => true, 'show_on_signage' => true, 'imported_at' => now('UTC')->format('Y-m-d H:i:s')];
            }
            // Local rooms and other local metadata remain untouched; reviewed withdrawals only hide Signage.
            $record->forceFill($data)->save();
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'object_type' => 'competitions', 'object_id' => $record->id,
                'before_value' => $fresh['before'] ? json_encode($fresh['before']) : null, 'after_value' => json_encode($data + ['action' => ($fresh['kind'] ?? '') === 'withdrawal' ? 'sfv_withdrawal' : 'sfv_import', 'reason' => $fresh['reason'] ?? null])]);

            return $record->id;
        });
    }
}
