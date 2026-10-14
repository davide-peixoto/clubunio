<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\DB;

class ContributionTrainingImporter
{
    public function run(): array
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }

        return $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'contributions-training-v1')->exists()) {
                return ['already_imported' => true];
            }
            $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $tenant) {
                throw new \LogicException('Foundation required');
            }
            $maps = [];
            foreach (['seasons', 'team_seasons', 'genders'] as $table) {
                $maps[$table] = $db->table('clubora.'.$table)->where('tenant_id', $tenant)->pluck('id', $table === 'genders' ? 'code' : 'legacy_id')->all();
            }
            $ref = function ($table, $key) use (&$maps) {
                if ($key === null || $key === '') {
                    return null;
                }
                if (! isset($maps[$table][$key])) {
                    throw new \LogicException('Unresolved '.$table.' reference');
                }

                return $maps[$table][$key];
            };
            $counts = [];
            foreach ($db->table('legacy_prod.Beitragspreise')->get() as $r) {
                $maps['prices'][$r->BeitragspreiseID] = $db->table('clubora.contribution_prices')->insertGetId([
                    'tenant_id' => $tenant, 'legacy_id' => $r->BeitragspreiseID, 'name' => $r->Bezeichnung, 'amount' => $r->BeitragCHF,
                    'bexio_product_id' => $r->bexioProductId, 'bexio_article_id' => $r->bexioArticleId, 'active' => $r->sdeletedAt === null]);
            }
            foreach ($db->table('legacy_prod.Beitragstabelle')->get() as $r) {
                $db->table('clubora.contribution_rules')->insert(['tenant_id' => $tenant, 'legacy_id' => $r->BeitragID,
                    'season_id' => $ref('seasons', $r->SaisonID), 'price_id' => $ref('prices', $r->BeitragspreiseID), 'gender_id' => $ref('genders', $r->Geschlaecht),
                    'year_from' => $r->JahrgangVon, 'year_to' => $r->JahrgangBis, 'active' => $r->sdeletedAt === null]);
            }
            $metadata = json_decode(file_get_contents(base_path('deploy/pmp-relations.json')), true, 512, JSON_THROW_ON_ERROR);
            $categories = ['SommerWinter' => 'period', 'Wochentag' => 'weekday', 'Feld' => 'field', 'Garderobe' => 'room', 'Seite' => 'side'];
            foreach ($categories as $source => $category) {
                $values = collect($metadata['tables']['Trainingszeiten'])->firstWhere('FldName', $source)['FldTagValues'];
                foreach (explode('|', $values) as $i => $entry) {
                    [$code,$name] = str_getcsv($entry, ',', '"', '');
                    $maps[$category][$code] = $db->table('clubora.training_options')->insertGetId(['tenant_id' => $tenant, 'category' => $category, 'code' => $code, 'name' => $name, 'sort_order' => $i]);
                }
            }
            foreach ($db->table('legacy_prod.Trainingszeiten')->get() as $r) {
                $db->table('clubora.training_slots')->insert(['tenant_id' => $tenant, 'legacy_id' => $r->TrainingID, 'team_season_id' => $ref('team_seasons', $r->TeamSaisonID),
                    'period_id' => $ref('period', $r->SommerWinter), 'weekday_id' => $ref('weekday', $r->Wochentag),
                    'field_id' => $ref('field', $r->Feld), 'room_id' => $ref('room', $r->Garderobe), 'side_id' => $ref('side', $r->Seite),
                    'starts_at' => $this->time($r->ZeitVon), 'ends_at' => $this->time($r->ZeitBis), 'hall' => $r->Halle, 'all_fields' => (bool) $r->trainingAll,
                    'without_main' => (bool) $r->trainingOhneHauptplatz, 'synthetic_only' => (bool) $r->trainingNurKunstrasen, 'website_visible' => (bool) $r->webseite, 'active' => $r->sdeletedAt === null]);
            }
            foreach (['contribution_prices', 'contribution_rules', 'training_options', 'training_slots'] as $table) {
                $counts[$table] = $db->table('clubora.'.$table)->where('tenant_id', $tenant)->count();
            }
            $db->table('clubora.imports')->insert(['name' => 'contributions-training-v1']);

            return $counts;
        });
    }

    public function time(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = str_replace('.', ':', trim($value));
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m) || (int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            throw new \LogicException('Invalid legacy training time');
        }

        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }
}
