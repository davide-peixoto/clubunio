<?php

namespace Tests\Feature;

use App\ClubUnio\Assistant\AssistantReadTools;
use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{BackofficeReadContext, ImpersonationService, ModulePermissionService};
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistantReadToolsTest extends TestCase
{
    private Account $actor;
    private Tenant $tenant;
    private AssistantReadTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        // Entirely synthetic in-memory schema: never connect to or mutate the shared PostgreSQL DB.
        $tables = [
            'accounts' => 'id INTEGER PRIMARY KEY, active BOOLEAN, api_only BOOLEAN, password TEXT',
            'tenants' => 'id INTEGER PRIMARY KEY, slug TEXT',
            'tenant_accounts' => 'tenant_id INTEGER, account_id INTEGER, role TEXT',
            'member_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT, ends_membership BOOLEAN',
            'members' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, status_id INTEGER, archived_at TEXT, exit_date TEXT, email TEXT, birth_date TEXT, ahv_encrypted TEXT',
            'registration_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT, workflow_key TEXT',
            'registrations' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, active BOOLEAN, first_name TEXT, last_name TEXT, submitted_at TEXT, status_id INTEGER, revision INTEGER DEFAULT 7, email TEXT, applicant_encrypted TEXT',
            'expense_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT',
            'expenses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, active BOOLEAN, status_id INTEGER, released_on TEXT, payment_released_on TEXT, paid_on TEXT, revision INTEGER DEFAULT 8, notes TEXT, bank_account_id INTEGER',
            'expense_lines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, expense_id INTEGER, active BOOLEAN, amount NUMERIC, storage_reference TEXT',
            'invoice_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT',
            'invoice_workflow_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT',
            'invoice_line_statuses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, code TEXT, name TEXT',
            'invoices' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, active BOOLEAN, status_id INTEGER, workflow_status_id INTEGER, rounding_cents INTEGER DEFAULT 0, revision INTEGER DEFAULT 9, notes TEXT',
            'invoice_lines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, invoice_id INTEGER, active BOOLEAN, status_id INTEGER, net_amount NUMERIC, gross_amount NUMERIC',
            'bexio_balances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, object_id INTEGER, object_type TEXT, remaining_cents INTEGER, observed_at TEXT',
            'bus_reservations' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, active BOOLEAN, status TEXT, starts_at TEXT, ends_at TEXT, location TEXT, revision INTEGER DEFAULT 10, description TEXT, decision_note TEXT',
            'pitch_requests' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, date TEXT, starts_at TEXT, duration_minutes INTEGER, opponent TEXT, revision INTEGER DEFAULT 11, notes TEXT, requester_account_id INTEGER',
        ];
        foreach ($tables as $table => $columns) {
            $db->statement('CREATE TABLE clubora.'.$table.' ('.$columns.')');
        }
        $this->insert('accounts', ['id' => 1, 'active' => true, 'api_only' => false, 'password' => 'NEVER-EXPOSE']);
        $this->insert('tenants', ['id' => 1, 'slug' => 'synthetic']);
        $this->insert('tenants', ['id' => 2, 'slug' => 'foreign']);
        $this->insert('tenant_accounts', ['account_id' => 1, 'role' => 'office']);
        $this->actor = Account::findOrFail(1);
        $this->tenant = Tenant::findOrFail(1);
        $this->tools = new AssistantReadTools;
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(Time::parse('2026-11-05T23:30:00Z'));
        // Catalog integration belongs to the main task; these are the requested DE/EN pairs.
        $catalogues = [];
        foreach ([
            'de' => [
                'invalid_arguments' => 'Ungueltige Leseparameter.',
                'summary' => ':shown von :total Treffern angezeigt.',
                'overdue_unavailable' => 'Ueberfaelligkeit ist ohne verlaessliches Faelligkeitsdatum nicht bestimmbar.',
                'reservation_coverage' => 'Nur Busreservationen und Platzanfragen; keine vollstaendige Belegungsplanung. Ohne Datum nur offene Anfragen aller Daten, mit Datum angefragte und genehmigte Buchungen dieses Tages.',
                'briefing_title' => 'Tagesbericht',
                'briefing_summary' => 'Aktueller Mitglieder- und Bearbeitungsstand; Reservationen am :date (Europe/Zurich). Keine historische Momentaufnahme.',
            ],
            'en' => [
                'invalid_arguments' => 'Invalid read parameters.',
                'summary' => 'Showing :shown of :total matches.',
                'overdue_unavailable' => 'Overdue status cannot be determined without an authoritative due date.',
                'reservation_coverage' => 'Bus reservations and pitch requests only; not a complete occupancy calendar. Without a date: pending requests across all dates. With a date: pending and approved bookings on that day.',
                'briefing_title' => 'Daily briefing',
                'briefing_summary' => 'Current membership and work status; reservations on :date (Europe/Zurich). Not a historical snapshot.',
            ],
        ] as $locale => $lines) {
            $catalogues[$locale] = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($lines as $key => $value) {
                $key = match ($key) {
                    'summary' => 'Showing :shown of :total matches.',
                    'briefing_summary' => 'Current membership and work status; reservations on :date (Europe/Zurich). Not a historical snapshot.',
                    default => 'assistant.read.'.$key,
                };
                $catalogues[$locale][$key] = $value;
            }
        }
        app('translator')->setLoaded(['*' => ['*' => $catalogues]]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::disconnect('clubunio');
        parent::tearDown();
    }

    private function insert(string $table, array $values): void
    {
        if (! in_array($table, ['accounts', 'tenants'], true)) {
            $values += ['tenant_id' => 1];
        }
        DB::connection('clubunio')->table('clubora.'.$table)->insert($values);
    }

    private function read(string $name, array $args = []): array
    {
        return $this->tools->execute($this->actor, $this->tenant, 'read_'.$name, $args);
    }

    private function forbidden(callable $read): void
    {
        try {
            $read();
            $this->fail('Expected fresh authorization to reject the read');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function seedSyntheticRows(): void
    {
        $this->insert('member_statuses', ['id' => 1, 'code' => 'active', 'name' => 'Aktiv', 'ends_membership' => false]);
        foreach (range(1, 25) as $id) {
            $this->insert('members', ['id' => $id, 'first_name' => 'Synthetic', 'last_name' => sprintf('Person %02d', $id), 'status_id' => 1,
                'email' => 'NEVER-EXPOSE', 'birth_date' => '2000-01-01', 'ahv_encrypted' => 'NEVER-EXPOSE']);
        }
        foreach (['submitted', 'review', 'trial', 'accepted', 'rejected', 'pool'] as $index => $workflow) {
            $this->insert('registration_statuses', ['id' => $index + 1, 'code' => 'code-'.$workflow, 'name' => $workflow, 'workflow_key' => $workflow]);
            $this->insert('registrations', ['id' => $index + 1, 'active' => true, 'status_id' => $index + 1,
                'first_name' => 'Applicant', 'last_name' => $workflow, 'submitted_at' => '2026-11-06 08:00:00', 'email' => 'NEVER-EXPOSE', 'applicant_encrypted' => 'NEVER-EXPOSE']);
        }
        foreach (['O', 'F', 'Z', 'R', 'B'] as $index => $code) {
            $this->insert('expense_statuses', ['id' => $index + 1, 'code' => $code, 'name' => $code]);
            $this->insert('expenses', ['id' => $index + 1, 'active' => true, 'status_id' => $index + 1, 'notes' => 'NEVER-EXPOSE', 'bank_account_id' => 999]);
            $this->insert('expense_lines', ['id' => $index + 1, 'expense_id' => $index + 1, 'active' => true, 'amount' => '12.50', 'storage_reference' => 'NEVER-EXPOSE']);
        }
        $this->insert('invoice_statuses', ['id' => 1, 'code' => 'O', 'name' => 'Offen']);
        $this->insert('invoice_line_statuses', ['id' => 1, 'code' => 'O', 'name' => 'Offen']);
        $this->insert('invoice_line_statuses', ['id' => 2, 'code' => 'B', 'name' => 'Bezahlt']);
        foreach (['draft', 'released', 'cancelled'] as $index => $code) {
            $this->insert('invoice_workflow_statuses', ['id' => $index + 1, 'code' => $code, 'name' => $code]);
            $this->insert('invoices', ['id' => $index + 1, 'active' => true, 'status_id' => 1, 'workflow_status_id' => $index + 1, 'notes' => 'NEVER-EXPOSE']);
            $this->insert('invoice_lines', ['id' => $index + 1, 'invoice_id' => $index + 1, 'active' => true,
                'status_id' => $index === 2 ? 2 : 1, 'net_amount' => '100.25', 'gross_amount' => '100.25']);
        }
        $this->insert('bexio_balances', ['id' => 1, 'object_id' => 1, 'object_type' => 'invoice', 'remaining_cents' => 1234, 'observed_at' => '2026-11-01 12:00:00']);
        $this->bus(1, '2026-11-06T09:00:00+01:00', '2026-11-06T10:00:00+01:00');
        $this->pitch(1, '2026-11-06', '11:00:00');
    }

    private function bus(int $id, string $start, string $end, array $extra = []): void
    {
        $this->insert('bus_reservations', $extra + ['id' => $id, 'active' => true, 'status' => 'ANGEFRAGT', 'starts_at' => $start,
            'ends_at' => $end, 'location' => 'Synthetic destination', 'description' => 'NEVER-EXPOSE', 'decision_note' => 'NEVER-EXPOSE']);
    }

    private function pitch(int $id, string $date, string $start, array $extra = []): void
    {
        $this->insert('pitch_requests', $extra + ['id' => $id, 'status' => 'pending', 'date' => $date, 'starts_at' => $start,
            'duration_minutes' => 90, 'opponent' => 'Synthetic opponent', 'notes' => 'NEVER-EXPOSE', 'requester_account_id' => 999]);
    }

    public function test_definitions_are_strict_flat_function_tools(): void
    {
        $definitions = $this->tools->definitions();
        $this->assertCount(6, $definitions);
        $this->assertSame(['read_daily_briefing', 'read_members', 'read_registrations', 'read_expenses', 'read_invoices', 'read_reservations'], array_column($definitions, 'name'));
        foreach ($definitions as $definition) {
            $this->assertSame('function', $definition['type']);
            $this->assertTrue($definition['strict']);
            $this->assertFalse($definition['parameters']['additionalProperties']);
            $this->assertSame(array_keys($definition['parameters']['properties']), $definition['parameters']['required']);
        }
    }

    public function test_members_are_bounded_literal_searches_with_honest_totals_and_no_personal_details(): void
    {
        $this->seedSyntheticRows();
        $result = $this->read('members', ['query' => '', 'limit' => 9999]);
        $this->assertSame(25, $result['total']);
        $this->assertCount(20, $result['rows']);
        $this->assertTrue($result['has_more']);
        $this->assertSame(2, $this->read('members', ['query' => 'Synthetic', 'limit' => 2])['returned']);
        $this->assertSame(0, $this->read('members', ['query' => "%' OR 1=1 --"])['total']);
        $this->assertSame(0, $this->read('members', ['query' => '_'])['total']);
        $this->insert('members', ['id' => 26, 'first_name' => 'Literal 100%_!', 'last_name' => 'Name']);
        $this->assertSame([26], array_column($this->read('members', ['query' => '%_!'])['rows'], 'id'));
        $this->assertStringNotContainsString('NEVER-EXPOSE', json_encode($result));
        $this->assertSame(['id', 'label', 'url', 'status', 'status_label'], array_keys($result['rows'][0]));
    }

    public function test_archived_exited_ended_and_foreign_members_are_excluded(): void
    {
        $this->seedSyntheticRows();
        $db = DB::connection('clubunio');
        $db->table('clubora.members')->where('id', 1)->update(['archived_at' => '2026-11-05']);
        $db->table('clubora.members')->where('id', 2)->update(['exit_date' => '2026-11-06']);
        $db->table('clubora.members')->where('id', 3)->update(['tenant_id' => 2]);
        $this->insert('member_statuses', ['id' => 2, 'code' => 'ended', 'name' => 'Ended', 'ends_membership' => true]);
        $db->table('clubora.members')->where('id', 4)->update(['status_id' => 2]);
        $this->assertSame(21, $this->read('members', ['query' => ''])['total']);
    }

    public function test_registration_and_expense_pending_filters_are_not_overdue_filters(): void
    {
        $this->seedSyntheticRows();
        $registrations = $this->read('registrations');
        $this->assertSame([1, 2, 3], array_column($registrations['rows'], 'id'));
        $this->assertSame(7, $registrations['rows'][0]['revision']);
        $this->assertSame(6, $this->read('registrations', ['status' => 'all'])['total']);
        $this->assertSame([2], array_column($this->read('registrations', ['status' => 'review', 'query' => 'Applicant'])['rows'], 'id'));
        $expenses = $this->read('expenses');
        $this->assertSame([2, 3], array_column($expenses['rows'], 'id'));
        $this->assertSame(8, $expenses['rows'][0]['revision']);
        $this->assertSame('12.5', $expenses['rows'][0]['amount']);
        $this->assertSame(1, $this->read('expenses', ['status' => 'B'])['total']);
        $this->assertSame(5, $this->read('expenses', ['status' => 'all'])['total']);
    }

    public function test_invoice_domain_open_balance_is_preserved_and_overdue_is_explicitly_unknown(): void
    {
        $this->seedSyntheticRows();
        $open = $this->read('invoices');
        $this->assertSame([1, 2], array_column($open['rows'], 'id'));
        $this->assertSame('12.34', $open['rows'][0]['open_amount']);
        $this->assertSame('100.25', $open['rows'][1]['open_amount']);
        $this->assertSame(9, $open['rows'][0]['revision']);
        $this->assertSame('2026-11-01 12:00:00', $open['rows'][0]['balance_observed_at']);
        $this->assertFalse($open['coverage']['provider_refreshed']);
        $this->assertSame(2, $this->read('invoices', ['status' => 'pending'])['total']);
        $this->assertSame(3, $this->read('invoices', ['status' => 'all'])['total']);
        $overdue = $this->read('invoices', ['status' => 'overdue']);
        $this->assertSame([], $overdue['rows']);
        $this->assertNull($overdue['total']);
        $this->assertNull($overdue['counts']['overdue']);
        $this->assertFalse($overdue['coverage']['overdue_supported']);
    }

    public function test_dated_reservations_include_overnight_but_not_adjacent_endpoints(): void
    {
        $this->seedSyntheticRows();
        $this->bus(2, '2026-11-05T23:00:00+01:00', '2026-11-06T01:00:00+01:00');
        $this->bus(3, '2026-11-05T22:00:00+01:00', '2026-11-06T00:00:00+01:00');
        $this->bus(4, '2026-11-07T00:00:00+01:00', '2026-11-07T01:00:00+01:00');
        $this->bus(5, '2026-11-06T12:00:00+01:00', '2026-11-06T13:00:00+01:00', ['status' => 'GENEHMIGT']);
        $this->bus(6, '2026-11-06T12:00:00+01:00', '2026-11-06T13:00:00+01:00', ['status' => 'STORNIERT']);
        $this->pitch(2, '2026-11-05', '23:30:00');
        $this->pitch(3, '2026-11-05', '22:30:00');
        $this->pitch(4, '2026-11-07', '00:00:00');
        $this->pitch(5, '2026-11-06', '12:00:00', ['status' => 'rejected']);
        $result = $this->read('reservations', ['kind' => 'all', 'date' => '2026-11-06']);
        $this->assertSame('2026-11-06', $result['coverage']['date']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(3, $result['counts']['bus_on_date']);
        $this->assertSame(2, $result['counts']['pitch_on_date']);
        $this->assertSame(4, $result['counts']['bus_pending_all_dates']);
        $this->assertSame(2, $result['counts']['bus_pending_before_date']);
        $this->assertSame(4, $result['counts']['pitch_pending_all_dates']);
        $this->assertSame(['pitch_requests'], $result['coverage']['pitch_sources']);
        $this->assertFalse($result['coverage']['complete_availability_calendar']);
        $pending = $this->read('reservations', ['kind' => 'all', 'date' => null]);
        $this->assertSame(8, $pending['total']);
        $this->assertSame('pending_all_dates', $pending['coverage']['scope']);
        $this->assertNull($pending['coverage']['date']);
        $this->assertSame('2026-11-06', $pending['coverage']['reference_date']);
        $this->assertSame(['ANGEFRAGT', 'pending'], array_values(array_unique(array_column($pending['rows'], 'status'))));
        foreach ($result['rows'] as $row) {
            $this->assertSame($row['kind'] === 'bus' ? 10 : 11, $row['revision']);
        }
    }

    public function test_all_reservations_are_capped_together_and_dst_day_coverage_is_exact(): void
    {
        foreach (range(1, 25) as $id) {
            $this->bus($id, '2026-03-29T10:00:00+02:00', '2026-03-29T11:00:00+02:00');
            $this->pitch($id, '2026-03-29', '12:00:00');
        }
        $result = $this->read('reservations', ['kind' => 'all', 'date' => '2026-03-29']);
        $this->assertSame(50, $result['total']);
        $this->assertSame(20, $result['returned']);
        $this->assertTrue($result['has_more']);
        $this->assertSame('2026-03-29T00:00:00+01:00', $result['coverage']['from']);
        $this->assertSame('2026-03-30T00:00:00+02:00', $result['coverage']['to_exclusive']);
    }

    public function test_briefing_is_flat_bounded_and_keeps_current_state_distinct_from_selected_day(): void
    {
        $this->seedSyntheticRows();
        $result = $this->read('daily_briefing');
        $this->assertArrayNotHasKey('sections', $result);
        $this->assertSame(['members', 'registrations', 'expenses', 'invoices', 'bus', 'pitch'], array_values(array_unique(array_column($result['rows'], 'module'))));
        $this->assertLessThanOrEqual(18, count($result['rows']));
        $this->assertSame(25, $result['counts']['members']['matching']);
        $this->assertNull($result['counts']['invoices']['overdue']);
        $this->assertFalse($result['coverage']['historical_snapshot']);
        $this->assertSame('2026-11-06', $result['coverage']['date']);
        foreach ($result['rows'] as $row) {
            $this->assertIsInt($row['id']);
            $this->assertIsString($row['label']);
            if ($row['module'] !== 'members') {
                $this->assertGreaterThan(0, $row['revision']);
            }
        }
        $past = $this->read('daily_briefing', ['date' => '2025-01-01']);
        $this->assertSame(25, $past['counts']['members']['matching']);
        $this->assertSame(0, $past['counts']['bus']['matching']);
        $this->assertStringNotContainsString('NEVER-EXPOSE', json_encode($result));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_all_module_reads_exclude_foreign_and_inactive_rows(): void
    {
        $this->seedSyntheticRows();
        foreach (['registrations', 'expenses', 'invoices', 'bus_reservations'] as $table) {
            DB::connection('clubunio')->table('clubora.'.$table)->where('id', 1)->update(['tenant_id' => 2]);
        }
        DB::connection('clubunio')->table('clubora.pitch_requests')->where('id', 1)->update(['tenant_id' => 2]);
        $this->assertSame([2, 3], array_column($this->read('registrations')['rows'], 'id'));
        DB::connection('clubunio')->table('clubora.registrations')->where('id', 2)->update(['active' => false]);
        DB::connection('clubunio')->table('clubora.expenses')->where('id', 2)->update(['active' => false]);
        $this->assertSame([3], array_column($this->read('registrations')['rows'], 'id'));
        $this->assertSame([3], array_column($this->read('expenses')['rows'], 'id'));
        $this->assertSame([2], array_column($this->read('invoices')['rows'], 'id'));
        $this->assertSame([], $this->read('reservations', ['kind' => 'all'])['rows']);
    }

    public function test_fresh_role_account_tenant_and_link_checks_ignore_stale_snapshots(): void
    {
        $this->assertSame(0, $this->read('members', ['query' => ''])['total']);
        $frame = ['account' => 1, 'tenants' => collect([$this->tenant]), 'permissionCache' => []];
        request()->attributes->set(BackofficeReadContext::class, $frame);
        request()->attributes->set(MobileReadContext::class, ['stale' => true]);
        foreach (['trainer', 'technical_lead', 'member', 'profile'] as $role) {
            DB::connection('clubunio')->table('clubora.tenant_accounts')->update(['role' => $role]);
            $this->forbidden(fn () => $this->read('members', ['query' => '']));
        }
        DB::connection('clubunio')->table('clubora.tenant_accounts')->update(['role' => 'admin']);
        $this->assertSame(0, $this->read('members', ['query' => ''])['total']);
        DB::connection('clubunio')->table('clubora.accounts')->update(['active' => false]);
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        DB::connection('clubunio')->table('clubora.accounts')->update(['active' => true, 'api_only' => true]);
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        DB::connection('clubunio')->table('clubora.accounts')->update(['api_only' => false]);
        DB::connection('clubunio')->table('clubora.tenants')->where('id', 1)->delete();
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        $this->insert('tenants', ['id' => 1, 'slug' => 'synthetic']);
        DB::connection('clubunio')->table('clubora.tenant_accounts')->delete();
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        $this->assertSame($frame, request()->attributes->get(BackofficeReadContext::class));
        $this->assertSame(['stale' => true], request()->attributes->get(MobileReadContext::class));
    }

    public function test_simulation_in_attribute_or_session_is_denied_before_any_query(): void
    {
        request()->attributes->set('clubunio_impersonation', false);
        DB::connection('clubunio')->enableQueryLog();
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        $this->assertSame([], DB::connection('clubunio')->getQueryLog());
        request()->attributes->remove('clubunio_impersonation');
        request()->setLaravelSession(app('session')->driver());
        request()->session()->put(ImpersonationService::KEY, 'synthetic-support');
        $this->forbidden(fn () => $this->read('members', ['query' => '']));
        $this->assertSame([], DB::connection('clubunio')->getQueryLog());
    }

    public function test_foreign_tenant_is_denied(): void
    {
        $this->forbidden(fn () => $this->tools->execute($this->actor, Tenant::findOrFail(2), 'read_members', ['query' => '']));
    }

    public function test_each_module_requires_view_and_never_requires_approval(): void
    {
        $permissions = new class {
            public array $denied = [];
            public array $calls = [];
            public function allows($actor, $tenant, string $module, string $function): bool
            {
                $this->calls[] = [$module, $function];
                return $function === 'view' && ! in_array($module, $this->denied, true);
            }
            public function authorize($actor, $tenant, string $module, string $function): void
            {
                abort_unless($this->allows($actor, $tenant, $module, $function), 403);
            }
        };
        app()->instance(ModulePermissionService::class, $permissions);
        $this->read('daily_briefing');
        $this->assertSame(['view'], array_values(array_unique(array_column($permissions->calls, 1))));
        foreach (['members', 'registrations', 'expenses', 'invoices', 'bus', 'competitions'] as $module) {
            $permissions->denied = [$module];
            $name = in_array($module, ['bus', 'competitions'], true) ? 'reservations' : $module;
            $args = $name === 'reservations' ? ['kind' => $module === 'bus' ? 'bus' : 'pitch'] : ($name === 'members' ? ['query' => ''] : []);
            $this->forbidden(fn () => $this->read($name, $args));
            $this->forbidden(fn () => $this->read('daily_briefing'));
        }
    }

    public function test_invalid_arguments_and_unknown_tools_never_reach_module_data(): void
    {
        foreach ([['members', []], ['members', ['query' => null]], ['members', ['query' => []]],
            ['members', ['query' => '', 'limit' => '10']], ['members', ['query' => '', 'limit' => 0]],
            ['members', ['query' => str_repeat('x', 121)]], ['members', ['query' => '', 'sql' => 'select *']],
            ['daily_briefing', ['date' => '2026-02-30']], ['daily_briefing', ['date' => 'tomorrow']],
            ['daily_briefing', ['date' => '2026-11-06T00:00:00Z']], ['daily_briefing', ['date' => 1]],
            ['registrations', ['status' => 'fake']], ['expenses', ['status' => 'overdue']],
            ['invoices', ['status' => []]], ['reservations', ['kind' => 'private_chat']], ['secrets', []],
        ] as [$name, $args]) {
            try {
                $this->read($name, $args);
                $this->fail('Invalid arguments must be rejected: '.$name);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_titles_summaries_validation_and_lookup_fallbacks_are_translated(): void
    {
        $this->seedSyntheticRows();
        foreach (['de' => ['Mitglieder', 'Tagesbericht', 'Offen'], 'en' => ['Members', 'Daily briefing', 'Open']] as $locale => [$title, $briefing, $status]) {
            app()->setLocale($locale);
            $result = $this->read('members', ['query' => '']);
            $this->assertSame($title, $result['title']);
            $this->assertStringContainsString('20', $result['summary']);
            $this->assertStringContainsString('25', $result['summary']);
            $this->assertSame($locale === 'de' ? '20 von 25 Treffern angezeigt.' : 'Showing 20 of 25 matches.', $result['summary']);
            $this->assertStringNotContainsString('assistant.read.', $result['summary']);
            $this->assertSame($briefing, $this->read('daily_briefing')['title']);
            $this->assertSame($status, $this->read('invoices')['rows'][0]['status_label']);
            $this->assertStringNotContainsString('assistant.read.', $this->read('invoices', ['status' => 'overdue'])['summary']);
        }
    }

    public function test_empty_briefing_still_reports_every_module_count(): void
    {
        $result = $this->read('daily_briefing');
        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['total']);
        foreach (['members', 'registrations', 'expenses', 'invoices', 'bus', 'pitch'] as $module) {
            $this->assertSame(0, $result['counts'][$module]['matching']);
            $this->assertSame(0, $result['counts'][$module]['returned']);
        }
        $this->assertSame(0, $result['counts']['bus']['bus_pending_all_dates']);
        $this->assertSame(0, $result['counts']['pitch']['pitch_pending_all_dates']);
        $this->assertNull($result['counts']['invoices']['overdue']);
    }

    public function test_result_links_match_existing_routes_and_pitch_booking_contract(): void
    {
        $this->seedSyntheticRows();
        $routes = app('router')->getRoutes();
        $names = [
            'members' => 'filament.clubunio.resources.members.view',
            'registrations' => 'filament.clubunio.resources.registrations.index',
            'expenses' => 'filament.clubunio.resources.expenses.view',
            'invoices' => 'filament.clubunio.resources.invoices.view',
            'bus' => 'clubunio.workspace.bus.show',
            'pitch' => 'filament.clubunio.pages.pitch-planning',
        ];
        foreach ($this->read('daily_briefing')['rows'] as $row) {
            $request = \Illuminate\Http\Request::create($row['url'], 'GET');
            $this->assertSame($names[$row['module']], $routes->match($request)->getName());
            if ($row['module'] === 'pitch') {
                $this->assertSame((string) $row['id'], $request->query('booking'));
                $this->assertSame('2026-11-06', $request->query('date'));
            }
        }
    }

    public function test_execution_only_reads_safe_projections_without_business_mutations(): void
    {
        $this->seedSyntheticRows();
        $db = DB::connection('clubunio');
        $db->enableQueryLog();
        $this->read('daily_briefing');
        foreach ($db->getQueryLog() as $query) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/\b(password|applicant_encrypted|bank_account_id|storage_reference|decision_note|requester_account_id|ahv_encrypted|notes)\b/i', $query['query']);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
