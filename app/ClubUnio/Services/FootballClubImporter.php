<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

class FootballClubImporter
{
    public function run(): array
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }

        return $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261012, 34)');
            if ($db->table('clubora.imports')->where('name', 'football-clubs-v1')->exists()) {
                return ['already_imported' => true];
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->lockForUpdate()->first();
            if (! $tenant || $db->table('clubora.football_clubs')->where('tenant_id', $tenant->id)->exists()) {
                throw new \LogicException('Missing tenant or nonempty club directory');
            }
            $result = $this->prepare($db->table('legacy_prod.Vereine')->orderBy('_migration_row')->get());
            foreach ($result['clubs'] as $row) {
                $db->table('clubora.football_clubs')->insert($row + ['tenant_id' => $tenant->id]);
            }
            $db->table('clubora.imports')->insert(['name' => 'football-clubs-v1']);

            return ['imported' => count($result['clubs']), 'conflicts' => $result['conflicts'], 'invalid_rows' => $result['invalid_rows']];
        });
    }

    public function prepare(iterable $rows): array
    {
        $groups = [];
        $invalid = 0;
        foreach ($rows as $row) {
            $number = filter_var($row->VereinID, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $name = trim((string) $row->Vereinsname);
            if ($number === false || $name === '' || mb_strlen($name) > 255) {
                $invalid++;

                continue;
            }
            $groups[$number][] = ['name' => $name, 'active' => empty($row->sdeletedAt)];
        }
        $clubs = $conflicts = [];
        foreach ($groups as $number => $group) {
            $active = array_values(array_filter($group, fn ($r) => $r['active']));
            $names = array_values(array_unique(array_column($active ?: $group, 'name')));
            if (count($names) !== 1) {
                $conflicts[] = $number;

                continue;
            }
            $clubs[] = ['club_number' => $number, 'name' => $names[0], 'active' => count($active) > 0];
        }

        return ['clubs' => $clubs, 'conflicts' => $conflicts, 'invalid_rows' => $invalid];
    }
}
