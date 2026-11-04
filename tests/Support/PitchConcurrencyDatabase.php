<?php

namespace Tests\Support;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MessageEventCatalog, MessageLanguageService};
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};

/** No business rows are copied. Every domain query is redirected before execution. */
final class PitchConcurrencyDatabase
{
    public const TABLES = ['tenants', 'accounts', 'tenant_accounts', 'account_roles', 'access_profiles',
        'seasons', 'teams', 'team_seasons', 'training_options', 'training_slots', 'competitions',
        'facility_statuses', 'field_availabilities', 'tenant_settings', 'lookup_translations',
        'object_changes', 'members', 'member_statuses', 'team_staff', 'staff_functions',
        'technical_leads', 'account_member_links', 'message_templates', 'message_template_revisions',
        'notifications', 'mobile_inbox', 'mobile_sessions', 'mobile_push_devices', 'mobile_push_bindings', 'mobile_push_outbox'];

    public static function checkName(string $schema): void
    {
        if (! preg_match('/^pitch_race_[a-f0-9]{24}$/D', $schema)) {
            throw new \LogicException('Refusing an unowned concurrency schema name');
        }
    }

    public static function connect(string $schema): PostgresConnection
    {
        self::checkName($schema);
        if (getenv('CLUBUNIO_TEST_PITCH_CONCURRENCY') !== '1' || ! app()->environment('testing')) {
            throw new \LogicException('Explicit testing-only concurrency opt-in required');
        }
        $config = config('database.connections.clubunio');
        if ($config['driver'] !== 'pgsql' || $config['database'] !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Expected reviewed ClubUnio PostgreSQL connection');
        }
        $config['search_path'] = $schema;
        $config['name'] = 'clubunio';
        DB::purge('clubunio');
        DB::extend('clubunio', function () use ($config, $schema) {
            $pdo = (new PostgresConnector)->connect($config);
            return new class($pdo, $config['database'], '', $config, $schema) extends PostgresConnection {
                public function __construct($pdo, $database, $prefix, $config, private string $schema)
                {
                    parent::__construct($pdo, $database, $prefix, $config);
                }

                public function beginTransaction()
                {
                    parent::beginTransaction();
                    if ($this->transactionLevel() === 1) {
                        $this->statement("SET LOCAL statement_timeout = '20s'");
                        $this->statement("SET LOCAL lock_timeout = '12s'");
                        $this->statement("SET LOCAL application_name = '{$this->schema}'");
                    }
                }

                protected function run($query, $bindings, \Closure $callback)
                {
                    $query = str_replace(['"clubora".', 'clubora.', "'clubora'"],
                        ['"'.$this->schema.'".', $this->schema.'.', "'{$this->schema}'"], $query);
                    $bindings = array_map(fn ($value) => $value === 'clubora' ? $this->schema : $value, $bindings);
                    if (preg_match('/\bclubora\b/i', $query)) {
                        throw new \LogicException('Unredirected business schema query');
                    }
                    return parent::run($query, $bindings, $callback);
                }
            };
        });
        config(['mail.default' => 'array', 'mail.mailers.clubunio_test' => ['transport' => 'array'],
            'clubunio-mobile-push.enabled' => true, 'clubunio-mobile-push.delivery_enabled' => false,
            'clubunio-integrations.test_mail_enabled' => false,
            'clubunio-integrations.pitch_planning_test_mail_enabled' => false]);
        Http::preventStrayRequests();
        Mail::fake();
        return DB::connection('clubunio');
    }

    public static function create(PostgresConnection $db, string $schema): void
    {
        self::checkName($schema);
        $pdo = $db->getPdo();
        $db->transaction(function () use ($db, $pdo, $schema) {
            $pdo->exec('CREATE SCHEMA "'.$schema.'"');
            $pdo->exec('REVOKE ALL ON SCHEMA "'.$schema.'" FROM PUBLIC');
            foreach (self::TABLES as $table) {
                // LIKE copies no rows, FKs or triggers. Identity sequences are independent.
                $pdo->exec("CREATE TABLE $schema.$table (LIKE clubora.$table INCLUDING ALL)");
                $defaults = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='$schema' AND table_name='$table' AND column_default LIKE 'nextval(%'")->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($defaults as $column) {
                    if (! preg_match('/^[a-z_]+$/D', $column)) throw new \LogicException('Unexpected catalog column');
                    $pdo->exec("ALTER TABLE $schema.$table ALTER COLUMN $column DROP DEFAULT");
                    $pdo->exec("ALTER TABLE $schema.$table ALTER COLUMN $column ADD GENERATED BY DEFAULT AS IDENTITY");
                }
            }
            (require database_path('clubunio/2026_11_04_001500_pitch_planning.php'))->up();
            (require database_path('clubunio/2026_11_04_001600_pitch_planning_notifications.php'))->up();
            (require database_path('clubunio/2026_11_04_001700_pitch_weekly_areas.php'))->up();
            (require database_path('clubunio/2026_11_04_001800_pitch_request_idempotency.php'))->up();
            $foreign = $pdo->query("SELECT count(*) FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_class r ON r.oid=c.confrelid JOIN pg_namespace rn ON rn.oid=r.relnamespace WHERE n.nspname='$schema' AND rn.nspname <> '$schema'")->fetchColumn();
            $defaults = $pdo->query("SELECT count(*) FROM pg_attrdef d JOIN pg_class t ON t.oid=d.adrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='$schema' AND pg_get_expr(d.adbin,d.adrelid) LIKE '%clubora%'")->fetchColumn();
            if ($foreign || $defaults) throw new \LogicException('Fixture references business schema');
        });
    }

    public static function seed(PostgresConnection $db): array
    {
        $tenant = Tenant::create(['slug' => 'pitch-race-fixture', 'name' => 'Synthetic pitch race']);
        $actor = new Account;
        $actor->forceFill(['username' => 'pitch-race@example.invalid', 'display_name' => 'Synthetic operator',
            'password' => 'not-a-login-hash', 'active' => true, 'preferred_locale' => 'en'])->save();
        $insert = fn ($table, $data) => $db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $tenant->id]);
        $insert('tenant_accounts', ['account_id' => $actor->id, 'role' => 'admin']);
        $season = $insert('seasons', ['name' => 'Synthetic season', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season',
            'value_type' => 'string', 'encrypted_value' => Crypt::encryptString((string) $season)]);
        $team = $insert('teams', ['name' => 'Synthetic team']);
        $team = $insert('team_seasons', ['season_id' => $season, 'team_id' => $team, 'label' => 'Synthetic team']);
        $field = $insert('training_options', ['category' => 'field', 'code' => 'RACE', 'name' => 'Synthetic field']);
        $status = $insert('facility_statuses', ['code' => 'RACE', 'name' => 'Open', 'blocks_training' => false]);
        $insert('field_availabilities', ['field_id' => $field, 'status_id' => $status]);
        $member = $insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Staff', 'birth_date' => '1980-01-01']);
        $insert('account_member_links', ['account_id' => $actor->id, 'member_id' => $member,
            'kind' => 'self', 'state' => 'approved', 'source' => 'pitch-race']);
        $function = $insert('staff_functions', ['code' => 'TRAINER', 'name' => 'Trainer']);
        $insert('team_staff', ['team_season_id' => $team, 'member_id' => $member, 'staff_function_id' => $function]);
        $insert('technical_leads', ['team_season_id' => $team, 'member_id' => $member]);
        $db->table('clubora.account_roles')->insert(['code' => 'profile', 'name' => 'Synthetic profile', 'description' => 'Concurrency fixture']);
        $session = '00000000-0000-4000-8000-000000000001';
        $device = '00000000-0000-4000-8000-000000000002';
        $db->table('clubora.mobile_sessions')->insert(['id' => $session, 'account_id' => $actor->id,
            'token_hash' => str_repeat('1', 64), 'device_name' => 'Synthetic race device', 'expires_at' => now()->addDay()->toIso8601String()]);
        $db->table('clubora.mobile_push_devices')->insert(['id' => $device, 'proof_hash' => str_repeat('2', 64),
            'app_id' => 'synthetic.pitch-race', 'platform' => 'android', 'origin' => 'https://example.invalid',
            'token_cipher' => 'not-a-provider-token', 'token_hash' => str_repeat('3', 64)]);
        $db->table('clubora.mobile_push_bindings')->insert(['id' => '00000000-0000-4000-8000-000000000003',
            'device_id' => $device, 'account_id' => $actor->id, 'session_id' => $session, 'created_at' => now()->subMinute()->toIso8601String()]);
        foreach (array_keys(config('clubunio-languages.enabled')) as $language) {
            foreach (MessageEventCatalog::all($language) as $key => $event) {
                if (! str_starts_with($key, 'pitch.')) continue;
                foreach ($event['channels'] as $channel) {
                    $insert('message_templates', ['event_key' => $key, 'channel' => $channel,
                        'locale' => MessageLanguageService::templateLocale($language), 'title' => $event['title'],
                        'body' => $event['body'], 'active' => true, 'revision' => 1]);
                }
            }
        }
        return compact('tenant', 'actor', 'team', 'field', 'member');
    }

    public static function counts(PostgresConnection $db): array
    {
        $counts = [];
        foreach (['pitch_requests', 'pitch_request_idempotency', 'object_changes', 'pitch_planning_events', 'notifications', 'mobile_inbox', 'mobile_push_outbox'] as $table) {
            $counts[$table] = $db->table('clubora.'.$table)->count();
        }
        return $counts;
    }

    public static function drop(PostgresConnection $db, string $schema): bool
    {
        self::checkName($schema);
        while ($db->transactionLevel()) $db->rollBack();
        $db->getPdo()->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        return ! $db->selectOne('SELECT EXISTS(SELECT 1 FROM pg_namespace WHERE nspname=?) AS present', [$schema])->present;
    }
}
