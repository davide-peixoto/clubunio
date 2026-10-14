<?php

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\AccountLinksImporter;
use App\ClubUnio\Services\ClubObjectsImporter;
use App\ClubUnio\Services\ContributionTrainingImporter;
use App\ClubUnio\Services\FacilityImporter;
use App\ClubUnio\Services\FootballClubImporter;
use App\ClubUnio\Services\MemberDetailsImporter;
use App\ClubUnio\Services\MemberDossierImporter;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\RegistrationDetailsImporter;
use App\ClubUnio\Services\RegistrationMessageService;
use App\ClubUnio\Services\StatusStyleService;
use App\ClubUnio\Services\TeamAssignmentsImporter;
use App\ClubUnio\Services\TestMailService;
use App\ClubUnio\Services\TestOutboxDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('clubunio:import-football-clubs', function () {
    $this->line(json_encode(app(FootballClubImporter::class)->run(), JSON_THROW_ON_ERROR));
})->purpose('Import club directory from immutable snapshot without guessing conflicting numbers');

Artisan::command('clubunio:import-facilities', function () {
    $this->line(json_encode(app(FacilityImporter::class)->run(), JSON_THROW_ON_ERROR));
})->purpose('Import facilities and competitions from immutable snapshot only');

Artisan::command('clubunio:seed-status-styles', function () {
    if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected target');
    }
    foreach (Tenant::all() as $tenant) {
        $this->line('Added colors: '.app(StatusStyleService::class)->seed($tenant));
    }
})->purpose('Seed missing display colors without changing existing preferences');

Artisan::command('clubunio:import-contributions-training', function () {
    $this->line(json_encode(app(ContributionTrainingImporter::class)->run(), JSON_THROW_ON_ERROR));
})->purpose('Import contributions and training slots from immutable snapshot only');

Artisan::command('clubunio:import-registration-details', function () {
    $this->line('Imported: '.app(RegistrationDetailsImporter::class)->run());
})->purpose('Import encrypted registration details and document references from snapshot only');

Artisan::command('clubunio:send-registration-mail {--limit=20}', function () {
    $this->line(json_encode(app(RegistrationMessageService::class)->dispatch((int) $this->option('limit')), JSON_THROW_ON_ERROR));
})->purpose('Send committed registration messages to the fixed test mailbox only');

Schedule::command('clubunio:send-registration-mail')->everyMinute()->withoutOverlapping();

Artisan::command('clubunio:send-expense-mail {--limit=20}', function () {
    $this->info(json_encode(app(TestOutboxDispatcher::class)->dispatch('expense_events', (int) $this->option('limit')), JSON_THROW_ON_ERROR));
});
Schedule::command('clubunio:send-expense-mail')->everyMinute()->withoutOverlapping();

Artisan::command('clubunio:import-account-links', function () {
    $this->line(json_encode(app(AccountLinksImporter::class)->run(), JSON_THROW_ON_ERROR));
})->purpose('Import account-member identities and pending parent proposals from the immutable snapshot');

Artisan::command('clubunio:test-mail {--tenant=sv-sissach} {--account=} {--event=account.password_changed}', function () {
    $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
    $actor = Account::where('username', $this->option('account'))->firstOrFail();
    $template = MessageTemplate::where('tenant_id', $tenant->id)->where('event_key', $this->option('event'))->where('channel', 'email')->where('locale', 'de-CH')->firstOrFail();
    $status = app(TestMailService::class)->sendPreview($actor, $tenant, $template->id, (string) Str::uuid());
    $this->line('Test mail status: '.$status.'; recipient: admin@svsissach.ch');

    return $status === 'accepted' ? 0 : 1;
})->purpose('Send one sample template to the fixed test mailbox only');

Artisan::command('clubunio:seed-message-templates', function () {
    if (DB::connection('clubunio')->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected target');
    }
    foreach (Tenant::all() as $tenant) {
        app(MessageTemplateService::class)->seed($tenant);
    }
    $this->info('Missing message templates created. Existing texts unchanged. No messages sent.');
})->purpose('Seed missing templates without overwriting tenant customizations');

Artisan::command('clubunio:import-member-dossier', function () {
    try {
        app(MemberDossierImporter::class)->run();
        $this->info('Member dossier imported or already present.');
    } catch (Throwable $e) {
        $this->error('Import rolled back: '.get_class($e).' code '.$e->getCode());
        if ($e instanceof LogicException) {
            $this->error($e->getMessage());
        }

        return 1;
    }
})->purpose('Import member relations and finance read models from the existing PROD snapshot');

Artisan::command('clubunio:import-team-assignments', function () {
    try {
        app(TeamAssignmentsImporter::class)->run();
        $this->info('Team assignments imported or already present.');
    } catch (Throwable $e) {
        $this->error('Import rolled back: '.get_class($e).' code '.$e->getCode());
        if ($e instanceof LogicException) {
            $this->error($e->getMessage());
        }

        return 1;
    }
})->purpose('Import team players, staff and technical leads from snapshot only');

Artisan::command('clubunio:import-member-details', function () {
    try {
        app(MemberDetailsImporter::class)->run();
        $this->info('Member details imported or already present.');
    } catch (Throwable $e) {
        $this->error('Import rolled back: '.get_class($e).' code '.$e->getCode());
        if ($e instanceof LogicException) {
            $this->error($e->getMessage());
        }

        return 1;
    }
})->purpose('Import extended members, families and document metadata from snapshot only');

Artisan::command('clubunio:import-objects', function () {
    try {
        app(ClubObjectsImporter::class)->run();
        $this->info('Club objects imported or already present.');
    } catch (Throwable $e) {
        $this->error('Import rolled back: '.get_class($e).' code '.$e->getCode());
        if ($e instanceof LogicException) {
            $this->error($e->getMessage());
        }

        return 1;
    }
})->purpose('Import teams, seasons, registrations and lookup tables into ClubUnio only');

Artisan::command('clubunio:status', function () {
    $db = DB::connection('clubunio');
    foreach (['tenants', 'accounts', 'members', 'tenant_settings', 'teams', 'seasons', 'team_seasons', 'registrations', 'member_statuses', 'member_types', 'registration_statuses'] as $table) {
        $this->line($table.': '.$db->table('clubora.'.$table)->count());
    }
    $this->line('Outbound enabled: '.(config('clubunio.outbound_enabled') ? 'yes' : 'no'));
})->purpose('Read only ClubUnio counts without confidential values');

Artisan::command('clubunio:foundation', function () {
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected database');
    }
    $db->statement('CREATE SCHEMA IF NOT EXISTS clubora');
    $db->statement('REVOKE ALL ON SCHEMA clubora FROM PUBLIC');
    config(['database.migrations.table' => 'clubora.migrations']);
    $this->call('migrate', ['--database' => 'clubunio', '--path' => 'database/clubunio', '--force' => true]);
})->purpose('Create only the new ClubUnio schema; preserve the PROD snapshot');

Artisan::command('clubunio:import-foundation', function () {
    $db = DB::connection('clubunio');
    if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
        throw new LogicException('Unexpected database');
    }
    try {
        $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'foundation-v1')->exists()) {
                return;
            }
            if ($db->table('clubora.tenants')->exists()) {
                throw new LogicException('Non-empty foundation');
            }
            $tenant = $db->table('clubora.tenants')->insertGetId(['slug' => 'sv-sissach', 'name' => 'SV Sissach']);
            foreach ($db->table('legacy_prod.Users')->orderBy('Username')->get() as $u) {
                $active = $u->sdeletedAt === null && in_array(strtoupper((string) $u->Activated), ['1', 'Y', 'TRUE'], true)
                    && ! in_array(strtoupper((string) $u->Locked), ['1', 'Y', 'TRUE'], true);
                $id = $db->table('clubora.accounts')->insertGetId([
                    'username' => mb_strtolower(trim($u->Username)),
                    'display_name' => trim(($u->First_Name ?? '').' '.($u->Last_Name ?? '')) ?: $u->Username,
                    'password' => $u->Password ?? '', 'active' => $active,
                ]);
                $level = (int) $u->User_Level;
                $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant, 'account_id' => $id,
                    'role' => match ($level) {
                        -1 => 'admin',1 => 'office',default => 'member'
                    }, 'legacy_user_level' => $level]);
            }
            $members = [];
            foreach ($db->table('legacy_prod.Mitglieder')->whereNull('sdeletedAt')->orderBy('MitgliederID')->get() as $m) {
                $members[] = ['tenant_id' => $tenant, 'legacy_id' => $m->MitgliederID,
                    'first_name' => $m->Vorname, 'last_name' => $m->Nachname, 'company' => $m->Firma ?? null,
                    'email' => $m->Email ?? null, 'city' => $m->Ort ?? null, 'recorded_at' => $m->ErfasstAm ?? null];
            }
            foreach (array_chunk($members, 100) as $batch) {
                $db->table('clubora.members')->insert($batch);
            }
            foreach (['iniApp' => 'app', 'iniBexio' => 'bexio', 'iniWhatsApp' => 'integrations'] as $table => $area) {
                foreach ($db->table('legacy_prod.'.$table)->get() as $s) {
                    $db->table('clubora.tenant_settings')->insert(['tenant_id' => $tenant, 'area' => $area,
                        'key' => $s->ParameterName, 'label' => $s->ParameterName, 'value_type' => 'string',
                        'encrypted_value' => Crypt::encryptString((string) $s->ParameterValue),
                        // Unknown historical parameters remain confidential pending classification.
                        'is_secret' => true, 'enabled' => false]);
                }
            }
            $ini = $db->table('legacy_prod.iniParameter')->where('iniID', 1)->first();
            if ($ini) {
                foreach (['SaisonID', 'SommerWinter', 'StatusPlatz1', 'StatusPlatz2', 'StatusPlatz3'] as $key) {
                    $db->table('clubora.tenant_settings')->insert(['tenant_id' => $tenant, 'area' => 'general', 'key' => $key,
                        'label' => $key, 'value_type' => $key === 'SaisonID' ? 'integer' : 'string',
                        'encrypted_value' => Crypt::encryptString((string) $ini->$key), 'is_secret' => false, 'enabled' => false]);
                }
            }
            $db->table('clubora.imports')->insert(['name' => 'foundation-v1']);
        });
        $this->info('Foundation imported or already present. No outbound actions enabled.');
    } catch (Throwable $e) {
        $this->error('Import rolled back: '.get_class($e).' code '.$e->getCode().'. No source values logged.');
        if ($e instanceof QueryException) {
            $sql = $e->getSql();
            preg_match('/(?:insert into|select \* from) "([A-Za-z_]+)"/i', $sql, $match);
            $this->error('Table: '.($match[1] ?? 'metadata'));
        }

        return 1;
    }
})->purpose('Import initial SV Sissach data from the verified PostgreSQL snapshot');
