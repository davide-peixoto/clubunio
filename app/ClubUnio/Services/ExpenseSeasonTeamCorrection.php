<?php

namespace App\ClubUnio\Services;

use Illuminate\Database\Connection;

/** Explicit historical data repair; never called by the normal expense editor. */
final class ExpenseSeasonTeamCorrection
{
    public const KEY = 'expense_team_season_fix_20261103';

    public static function manifest(): array
    {
        // Native expense ID, legacy expense ID, receipt season, team, old/new TeamSeason.
        return [
            [46, 102, 31, 3, 229, 256], [62, 133, 30, 5, 262, 233],
            [129, 224, 33, 36, 276, 309], [172, 294, 34, 35, 312, 321],
            [192, 319, 33, 27, 319, 310], [206, 337, 33, 5, 317, 306],
            [230, 369, 35, 27, 319, 346], [232, 371, 35, 7, 323, 350],
            [235, 374, 35, 28, 320, 347], [236, 375, 35, 28, 320, 347],
            [237, 376, 35, 28, 320, 347], [268, 419, 34, 5, 344, 317],
            [269, 420, 34, 5, 344, 317], [335, 502, 35, 5, 370, 344],
        ];
    }

    public function snapshot(Connection $db, int $tenant, bool $lock = false): array
    {
        $ids = array_column(self::manifest(), 0);
        $query = $db->table('clubunio.expenses')->where('tenant_id', $tenant)->whereIn('id', $ids)->orderBy('id');
        if ($lock) { $query->lock('FOR UPDATE NOWAIT'); }
        $rows = $query->get()->keyBy('id');
        if ($rows->count() !== 14) { throw new \LogicException('Expected exactly fourteen approved expenses'); }
        $changes = []; $already = 0;
        foreach (self::manifest() as [$id, $legacy, $season, $team, $old, $new]) {
            $row = $rows->get($id);
            if ((int) $row->legacy_id !== $legacy || (int) $row->season_id !== $season || ! $row->active) {
                throw new \LogicException('Approved expense identity/season/state changed');
            }
            $original = $db->table('clubunio.team_seasons')->where('tenant_id', $tenant)->where('id', $old)->first();
            $targets = $db->table('clubunio.team_seasons')->where('tenant_id', $tenant)->where('team_id', $team)->where('season_id', $season);
            if ($lock) { $targets->lock('FOR SHARE'); }
            $targets = $targets->get();
            if (! $original || (int) $original->team_id !== $team || $targets->count() !== 1
                || (int) $targets->first()->id !== $new || ! $targets->first()->active) {
                throw new \LogicException('Same-team target is missing, inactive or ambiguous');
            }
            if ((int) $row->team_season_id === $new) { $already++; }
            elseif ((int) $row->team_season_id !== $old || (int) $row->revision !== 1) {
                throw new \LogicException('Approved expense was concurrently changed');
            }
            $changes[] = ['id' => $id, 'legacy_id' => $legacy, 'season_id' => $season, 'team_id' => $team,
                'old_team_season_id' => $old, 'new_team_season_id' => $new];
        }
        if ($already !== 0 && $already !== 14) { throw new \LogicException('Partial repair state requires review'); }
        if ($already === 14) {
            foreach ($changes as $change) {
                $evidence = $db->table('clubunio.object_changes')->where('tenant_id', $tenant)->where('object_type', 'expenses')->where('object_id', $change['id'])->get();
                if (! $evidence->contains(function ($audit) use ($change) {
                    $before = json_decode($audit->before_value ?? '', true);
                    $after = json_decode($audit->after_value ?? '', true);
                    return ($after['correction_key'] ?? null) === self::KEY
                        && (int) ($before['team_season_id'] ?? 0) === $change['old_team_season_id']
                        && (int) ($after['team_season_id'] ?? 0) === $change['new_team_season_id'];
                })) { throw new \LogicException('Already-correct state has no matching correction evidence'); }
            }
        }
        $lines = $db->table('clubunio.expense_lines')->where('tenant_id', $tenant)->whereIn('expense_id', $ids)->orderBy('id');
        if ($lock) { $lines->lock('FOR UPDATE NOWAIT'); }

        return ['changes' => $changes, 'already_applied' => $already === 14,
            'expenses' => $rows->values()->all(), 'lines' => $lines->get()->all()];
    }

    public function apply(Connection $db, int $tenant, int $actor, callable $backup): array
    {
        if ($db->transactionLevel() === 0) { throw new \LogicException('Explicit transaction required'); }
        $snapshot = $this->snapshot($db, $tenant, true);
        if ($snapshot['already_applied']) { return ['changed' => 0, 'already_applied' => true]; }
        $backup($snapshot);
        $original = collect($snapshot['expenses'])->keyBy('id');
        foreach ($snapshot['changes'] as $change) {
            $row = $original->get($change['id']);
            $updated = $db->table('clubunio.expenses')->where('tenant_id', $tenant)->where('id', $row->id)->where('revision', $row->revision)
                ->where('team_season_id', $change['old_team_season_id'])->where('season_id', $change['season_id'])
                ->update(['team_season_id' => $change['new_team_season_id'], 'revision' => $row->revision + 1, 'updated_at' => now()->toDateTimeString()]);
            if ($updated !== 1) { throw new \LogicException('Conditional correction failed'); }
            $db->table('clubunio.object_changes')->insert(['tenant_id' => $tenant, 'actor_id' => $actor, 'object_type' => 'expenses', 'object_id' => $row->id,
                'before_value' => json_encode(['team_season_id' => (int) $row->team_season_id, 'revision' => (int) $row->revision], JSON_THROW_ON_ERROR),
                'after_value' => json_encode(['team_season_id' => $change['new_team_season_id'], 'revision' => $row->revision + 1,
                    'correction_key' => self::KEY, 'reason' => 'User-approved historical correction: receipt season is authoritative; same team in that season.'], JSON_THROW_ON_ERROR)]);
        }
        $after = $this->snapshot($db, $tenant, true);
        foreach ($after['expenses'] as $row) {
            $beforeValues = (array) $original->get($row->id); $afterValues = (array) $row;
            foreach (['team_season_id', 'revision', 'updated_at'] as $field) { unset($beforeValues[$field], $afterValues[$field]); }
            if ($beforeValues !== $afterValues) { throw new \LogicException('Unexpected expense data change'); }
        }
        if (json_encode($snapshot['lines'], JSON_THROW_ON_ERROR) !== json_encode($after['lines'], JSON_THROW_ON_ERROR)) {
            throw new \LogicException('Expense line contents changed');
        }

        return ['changed' => 14, 'already_applied' => false, 'line_rows_unchanged' => count($snapshot['lines']), 'changes' => $snapshot['changes']];
    }
}
