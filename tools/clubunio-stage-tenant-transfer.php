<?php

declare(strict_types=1);

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{ActiveTrainingPlan, PitchPlanningProjection};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Crypt, DB, Http};

if (PHP_SAPI !== 'cli' || $argv !== [__FILE__, '--rehearse']) {
    fwrite(STDERR, "Use the absolute script path with --rehearse. No live transfer is enabled.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$phase = 'configuration';

try {
    $live = config('clubunio.connection');
    if (config('app.url') !== 'https://stage.clubunio.com' || ($live['database'] ?? null) !== 'clubunio_stage'
        || ($live['sslmode'] ?? null) !== 'verify-full' || config('clubunio.outbound_enabled')) {
        throw new LogicException('Expected Stage configuration.');
    }
    Http::preventStrayRequests();
    $sourceName = 'clubunio_stage_refresh_20261110';
    $targetName = 'clubunio_stage_transfer_rehearsal';
    config(['database.connections.refresh_source' => array_replace($live, ['database' => $sourceName]),
        'database.connections.clubunio' => array_replace($live, ['database' => $targetName])]);
    DB::purge('clubunio');
    $source = DB::connection('refresh_source')->getPdo();
    $target = DB::connection('clubunio')->getPdo();
    foreach ([$source, $target] as $connection) {
        $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }
    if ($source->query('SELECT current_database()')->fetchColumn() !== $sourceName
        || $target->query('SELECT current_database()')->fetchColumn() !== $targetName) {
        throw new LogicException('Unexpected database.');
    }
    $source->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $target->beginTransaction();
    $target->exec("SET LOCAL lock_timeout='3s'");
    $tenant = $source->query("SELECT * FROM clubunio.tenants WHERE slug='sv-sissach-refresh'")->fetch(PDO::FETCH_ASSOC);
    if (! $tenant || $source->query('SELECT count(*) FROM clubunio.tenants')->fetchColumn() !== '1'
        || $target->query("SELECT count(*) FROM clubunio.tenants WHERE slug='sv-sissach-refresh'")->fetchColumn() !== '0') {
        throw new LogicException('Unexpected tenant state.');
    }
    $operator = $target->query("SELECT id FROM clubunio.accounts WHERE lower(username)='davide.peixoto@svsissach.ch' AND active AND NOT api_only")->fetchAll(PDO::FETCH_COLUMN);
    if (count($operator) !== 1) {
        throw new LogicException('Expected a single approved operator.');
    }

    $phase = 'inventory';
    $allowed = explode(' ', 'access_profiles account_member_links accounts announcement_statuses banks billing_recipients bus_reservations bus_statuses buses club_events club_functions competitions contribution_prices contribution_rules countries document_types event_guests expense_lines expense_rounds expense_statuses expense_types expenses facility_statuses families family_members field_availabilities financial_adjustment_statuses football_clubs game_types genders invoice_categories invoice_line_statuses invoice_lines invoice_statuses invoice_workflow_statuses invoices material_receipt_statuses member_bank_accounts member_change_requests member_change_statuses member_documents member_functions member_keys member_notes member_statuses member_types members message_template_revisions message_templates mobile_module_options planning_options qualifications registration_documents registration_statuses registrations seasons signage_notices sponsor_run_statuses sponsorships staff_functions status_styles team_absences team_events team_invitations team_participations team_players team_seasons team_staff teams technical_leads tenant_accounts tenant_settings tenants training_options training_slots');
    $global = ['account_roles', 'imports', 'migrations'];
    $identifier = static function (string $name): string {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $name)) {
            throw new LogicException('Unexpected SQL identifier.');
        }
        return '"'.$name.'"';
    };
    $tables = [];
    foreach ($source->query("SELECT tablename FROM pg_tables WHERE schemaname='clubunio' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (in_array($name, $global, true)) continue;
        $rows = $source->query('SELECT * FROM clubunio.'.$identifier($name))->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) continue;
        if (! in_array($name, $allowed, true)) {
            $phase = 'unreviewed populated table '.$name;
            throw new LogicException('Unexpected populated table.');
        }
        foreach ($rows as $row) {
            if (! isset($row['id']) || (isset($row['tenant_id']) && $row['tenant_id'] !== $tenant['id'])
                || (! isset($row['tenant_id']) && ! in_array($name, ['accounts', 'tenants'], true))) {
                throw new LogicException('Unexpected source row ownership.');
            }
        }
        $tables[$name] = $rows;
    }
    if (count($tables['accounts']) !== 75 || count($tables['tenant_accounts']) !== 75 || count($tables['member_change_requests']) !== 18) {
        throw new LogicException('Unexpected reviewed snapshot counts.');
    }
    $fkSql = <<<'SQL'
        SELECT s.relname AS child, t.relname AS parent, a.attname AS child_key, b.attname AS parent_key,
               c.conname AS fk
        FROM pg_constraint c JOIN pg_class s ON s.oid=c.conrelid JOIN pg_class t ON t.oid=c.confrelid
        CROSS JOIN LATERAL unnest(c.conkey,c.confkey) AS k(skey,tkey)
        JOIN pg_attribute a ON a.attrelid=s.oid AND a.attnum=k.skey
        JOIN pg_attribute b ON b.attrelid=t.oid AND b.attnum=k.tkey
        WHERE c.contype='f' AND c.connamespace='clubunio'::regnamespace
        ORDER BY child,parent,fk,child_key
        SQL;
    $fks = $source->query($fkSql)->fetchAll(PDO::FETCH_ASSOC);
    if ($fks !== $target->query($fkSql)->fetchAll(PDO::FETCH_ASSOC)) {
        throw new LogicException('Source and target FK catalogs differ.');
    }
    $map = [];
    foreach ($tables as $name => $rows) {
        $phase = 'allocate IDs for '.$name;
        $ids = $target->query("SELECT nextval(pg_get_serial_sequence('clubunio.".$name."', 'id')) FROM generate_series(1,".count($rows).')')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $i => $row) $map[$name][$row['id']] = $ids[$i];
    }
    $resolve = static fn (string $table, $id) => $map[$table][$id] ?? throw new LogicException('Unresolved native ID.');
    $newTenant = $resolve('tenants', $tenant['id']);
    $groups = [];
    foreach ($fks as $fk) $groups[$fk['child']][$fk['fk']][] = $fk;
    $dependencies = [];
    foreach ($tables as $name => &$rows) {
        $phase = 'map '.$name;
        foreach ($rows as &$row) {
            $old = $row;
            $row['id'] = $resolve($name, $old['id']);
            foreach ($groups[$name] ?? [] as $group) {
                foreach ($group as $fk) if ($old[$fk['child_key']] === null) continue 2;
                $parent = $group[0]['parent'];
                if (in_array($parent, $global, true)) continue;
                $dependencies[$name][$parent] = true;
                foreach ($group as $fk) {
                    $key = $fk['child_key'];
                    $value = match ($fk['parent_key']) {
                        'id' => $resolve($parent, $old[$key]),
                        'tenant_id' => $resolve('tenants', $old[$key]),
                        'account_id' => $resolve('accounts', $old[$key]),
                        'code' => $old[$key],
                        default => throw new LogicException('Unsupported FK key.'),
                    };
                    if ($row[$key] !== $old[$key] && $row[$key] !== $value) throw new LogicException('Conflicting FK map.');
                    $row[$key] = $value;
                }
            }
            if (isset($old['tenant_id'])) $row['tenant_id'] = $newTenant;
            if ($name === 'accounts') {
                $row['username'] = 'stage-refresh-history-'.$old['id'].'@example.invalid';
                $row['password'] = '';
                $row['active'] = false;
                $row['api_only'] = true;
            }
            if ($name === 'tenant_accounts') $row['portal_enabled'] = false;
            foreach ($row as $key => $cipher) {
                if (! str_ends_with($key, '_encrypted') && $key !== 'encrypted_value') continue;
                if ($cipher !== null && $cipher !== '') Crypt::decryptString($cipher);
            }
            if ($name === 'tenant_settings') {
                if ($row['is_secret'] === 't') $row['enabled'] = false;
                $catalog = match ($row['key']) { 'SaisonID' => 'seasons', 'SIGNAGE_PERIOD_ID' => 'training_options', default => null };
                if ($catalog) $row['encrypted_value'] = Crypt::encryptString($resolve($catalog, Crypt::decryptString($old['encrypted_value'])));
            }
            if ($name === 'member_change_requests') {
                $snapshot = json_decode(Crypt::decryptString($old['snapshot_encrypted']), true, 32, JSON_THROW_ON_ERROR);
                foreach (['before', 'changes'] as $side) {
                    foreach (['nationality_id' => 'countries', 'billing_recipient_id' => 'billing_recipients'] as $key => $catalog) {
                        if (isset($snapshot[$side][$key])) $snapshot[$side][$key] = (int) $resolve($catalog, $snapshot[$side][$key]);
                    }
                }
                if (($snapshot['team_id'] ?? null) !== null) throw new LogicException('Unexpected request team ID.');
                $row['snapshot_encrypted'] = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
                // An imported open request must be reviewed against the new member version before application.
                $row['member_version'] = hash('sha256', 'refresh-review:'.$old['member_version']);
            }
        }
        unset($row);
    }
    unset($rows);
    $written = [];
    while (count($written) < count($tables)) {
        $before = count($written);
        foreach ($tables as $name => $rows) {
            if (isset($written[$name]) || array_diff_key($dependencies[$name] ?? [], $written)) continue;
            $phase = 'insert '.$name;
            $table = 'clubunio.'.$identifier($name);
            $columns = implode(',', array_map($identifier, array_keys($rows[0])));
            $insert = $target->prepare("INSERT INTO $table ($columns) SELECT $columns FROM json_populate_recordset(NULL::$table, CAST(? AS json))");
            foreach (array_chunk($rows, 250) as $batch) $insert->execute([json_encode($batch, JSON_THROW_ON_ERROR)]);
            $written[$name] = count($rows);
        }
        if (count($written) === $before) {
            $phase = 'unresolved dependency: '.implode(',', array_keys(array_diff_key($tables, $written)));
            throw new LogicException('Unresolved dependencies.');
        }
    }
    $phase = 'access and occupancy acceptance';
    $target->prepare("INSERT INTO clubunio.tenant_accounts(tenant_id,account_id,role,portal_enabled) VALUES (?,?,'office',true)")
        ->execute([$newTenant, $operator[0]]);
    $active = $target->prepare('SELECT a.id FROM clubunio.tenant_accounts m JOIN clubunio.accounts a ON a.id=m.account_id WHERE m.tenant_id=? AND a.active AND NOT a.api_only');
    $active->execute([$newTenant]);
    if ($active->fetchAll(PDO::FETCH_COLUMN) !== $operator) throw new LogicException('Unexpected active memberships.');
    $model = Tenant::whereKey($newTenant)->sole();
    $period = app(ActiveTrainingPlan::class)->period($model);
    $entries = app(PitchPlanningProjection::class)->entries($model, now('Europe/Zurich')->toDateString(), now('Europe/Zurich')->addWeek()->toDateString());
    $training = count(array_filter($entries, fn ($row) => $row['source'] === 'training'));
    if ($period?->code !== 'W' || $training < 1) throw new LogicException('Occupancy acceptance failed.');
    $target->exec('SET CONSTRAINTS ALL IMMEDIATE');
    $target->rollBack();
    $source->exec('ROLLBACK');
    echo json_encode(['mode' => 'rolled-back rehearsal', 'tables' => count($written), 'rows' => array_sum($written),
        'active_memberships' => 1, 'historical_memberships' => 75, 'requests' => $written['member_change_requests'],
        'training' => $training], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (isset($target) && $target->inTransaction()) $target->rollBack();
    fwrite(STDERR, 'Stage transfer stopped at '.$phase.' ('.get_class($error).', '.$error->getCode().').'.PHP_EOL);
    exit(1);
}
