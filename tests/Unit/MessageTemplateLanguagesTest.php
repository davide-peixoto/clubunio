<?php

namespace Tests\Unit;

use App\ClubUnio\Models\{Account, MessageTemplate, Registration, Tenant};
use App\ClubUnio\Services\{MessageEventCatalog, MessageLanguageService, MessageTemplateService, ModulePermissionService, RegistrationMessageService, TestMailGuard, TestOutboxDispatcher};
use Illuminate\Support\Facades\{Crypt, DB, Mail};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Isolated in-memory fixture: never connects to the configured/shared database. */
class MessageTemplateLanguagesTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'clubunio-languages.enabled' => ['de' => 'Deutsch', 'en' => 'English'], 'clubunio-languages.default' => 'de']);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        foreach ([
            'tenants' => 'id integer primary key, name text, default_locale text',
            'accounts' => 'id integer primary key, username text, preferred_locale text, active boolean',
            'tenant_accounts' => 'tenant_id integer, account_id integer, role text, portal_enabled boolean',
            'message_templates' => 'id integer primary key autoincrement, tenant_id integer, event_key text, channel text, locale text, title text, body text, active boolean, revision integer, created_at text, updated_at text, UNIQUE(tenant_id,event_key,channel,locale)',
            'message_template_revisions' => 'id integer primary key autoincrement, tenant_id integer, template_id integer, revision integer, title text, body text, active boolean, actor_id integer, created_at text, UNIQUE(tenant_id,template_id,revision)',
            'registration_events' => 'id integer primary key autoincrement, tenant_id integer, registration_id integer, revision integer, event_key text, team_season_id integer, technical_lead_member_id integer, delivery_state text, payload_encrypted text, finished_at text, error_code text',
            'notifications' => 'id integer primary key autoincrement, tenant_id integer, account_id integer, registration_event_id integer, title text, body text, object_type text, object_id integer',
            'team_seasons' => 'id integer, tenant_id integer, label text',
            'billing_recipients' => 'id integer, tenant_id integer, email_field text',
            'members' => 'id integer primary key, tenant_id integer, first_name text, email text, archived_at text, exit_date text, status_id integer',
            'member_statuses' => 'id integer, ends_membership boolean',
            'team_staff' => 'tenant_id integer, team_season_id integer, member_id integer, active boolean',
            'technical_leads' => 'tenant_id integer, team_season_id integer, member_id integer, active boolean',
            'account_member_links' => 'tenant_id integer, account_id integer, member_id integer, kind text, state text',
            'expense_lines' => 'id integer, expense_id integer, amount numeric, active boolean',
            'expense_events' => 'id integer primary key autoincrement, tenant_id integer, expense_id integer, revision integer, event_key text, delivery_state text, payload_encrypted text',
            'bus_reservations' => 'id integer, tenant_id integer, member_id integer, team_season_id integer, starts_at text',
        ] as $table => $columns) {
            $db->statement("CREATE TABLE clubora.$table ($columns)");
        }
        $db->statement('ALTER TABLE clubora.notifications ADD COLUMN expense_event_id integer');
        $db->table('clubora.tenants')->insert(['id' => 1, 'name' => 'Fixture Club', 'default_locale' => 'de']);
        $this->tenant = Tenant::findOrFail(1);
        $db->table('clubora.accounts')->insert([
            ['id' => 1, 'username' => 'english@example.invalid', 'preferred_locale' => 'en', 'active' => true],
            ['id' => 2, 'username' => 'german@example.invalid', 'preferred_locale' => 'de', 'active' => true],
            ['id' => 3, 'username' => 'foreign@example.invalid', 'preferred_locale' => 'en', 'active' => true],
        ]);
        $db->table('clubora.tenant_accounts')->insert([
            ['tenant_id' => 1, 'account_id' => 1, 'role' => 'office'],
            ['tenant_id' => 1, 'account_id' => 2, 'role' => 'admin'],
            ['tenant_id' => 2, 'account_id' => 3, 'role' => 'office'],
        ]);
        app(MessageTemplateService::class)->seed($this->tenant);
    }

    protected function tearDown(): void
    {
        DB::purge('clubunio');
        parent::tearDown();
    }

    public function test_all_canonical_templates_have_english_text_and_identical_placeholders(): void
    {
        $de = MessageEventCatalog::all('de');
        $en = MessageEventCatalog::all('en');
        $this->assertSame(array_keys($de), array_keys(require lang_path('message-templates/en.php')));
        foreach ($de as $key => $definition) {
            $this->assertSame($definition['channels'], $en[$key]['channels']);
            $this->assertSame($definition['variables'], $en[$key]['variables']);
            foreach (['title', 'body'] as $field) {
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $definition[$field], $original);
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $en[$key][$field], $translated);
                sort($original[1]);
                sort($translated[1]);
                $this->assertSame($original[1], $translated[1], "$key.$field");
            }
            foreach ($definition['channels'] as $channel) {
                $rendered = app(MessageTemplateService::class)->render($this->tenant, $key, $channel, MessageEventCatalog::samples('en'), 'en');
                $this->assertSame('en-GB', $rendered['locale']);
                $this->assertStringNotContainsString('{{', $rendered['body']);
            }
        }
    }

    public function test_seed_preserves_custom_text_history_and_inherits_disabled_german_variant(): void
    {
        $german = MessageTemplate::where('event_key', 'registration.received')->where('locale', 'de-CH')->firstOrFail();
        $german->forceFill(['title' => 'Tenant custom title', 'body' => 'Tenant custom text', 'active' => false])->save();
        MessageTemplate::where('event_key', 'registration.received')->where('locale', 'en-GB')->delete();
        DB::connection('clubunio')->table('clubora.message_template_revisions')->where('template_id', '<>', $german->id)->delete();
        $service = app(MessageTemplateService::class);
        $service->seed($this->tenant);
        $count = MessageTemplate::count();
        $service->seed($this->tenant);
        $this->assertSame($count, MessageTemplate::count());
        $this->assertSame('Tenant custom title', $german->fresh()->title);
        $this->assertSame(1, $german->revisions()->count());
        $this->assertNull($service->render($this->tenant, 'registration.received', 'email', [], 'en'));
    }

    public function test_recipient_language_ignores_operator_and_is_tenant_scoped(): void
    {
        app()->setLocale('en');
        $languages = app(MessageLanguageService::class);
        $this->assertSame('de', $languages->account($this->tenant, 2));
        $this->assertSame('de', $languages->account($this->tenant, 3));
        $this->assertSame('de', $languages->email($this->tenant, 'foreign@example.invalid'));
        $this->assertSame('en', $languages->email($this->tenant, ' ENGLISH@example.invalid '));
        app()->setLocale('de');
        $this->assertSame('en', $languages->account($this->tenant, 1));
        $this->tenant->default_locale = 'en';
        $this->assertSame('en', $languages->email($this->tenant, 'unknown@example.invalid'));
        $this->assertSame('en', $languages->resolve($this->tenant, 'xx'));
        config(['clubunio-languages.enabled' => ['de' => 'Deutsch']]);
        $this->assertSame('de', $languages->account($this->tenant, 1));
    }

    public function test_missing_variant_falls_back_but_disabled_variant_never_does(): void
    {
        $query = MessageTemplate::where('event_key', 'member.change.approved')->where('locale', 'en-GB');
        $query->update(['active' => false]);
        $service = app(MessageTemplateService::class);
        $this->assertNull($service->renderForAccount($this->tenant, 'member.change.approved', 'push', [], 1));
        $query->delete();
        $result = $service->renderForAccount($this->tenant, 'member.change.approved', 'push', [], 1);
        $this->assertSame('de-CH', $result['locale']);
        $this->assertSame('Mitgliederdaten aktualisiert', $result['title']);
    }

    public function test_english_edit_restore_and_revision_identity_are_preserved(): void
    {
        $permissions = \Mockery::mock(app(ModulePermissionService::class));
        $permissions->shouldReceive('authorize')->andReturnNull();
        $this->app->instance(ModulePermissionService::class, $permissions);
        $service = app(MessageTemplateService::class);
        $english = MessageTemplate::where('event_key', 'member.change.approved')->where('locale', 'en-GB')->firstOrFail();
        $actor = (new Account)->forceFill(['id' => 1]);
        $service->save($actor, $this->tenant, $english->id, ['revision' => 1, 'title' => 'Custom English', 'body' => 'Custom body', 'active' => true, 'locale' => 'de-CH']);
        $this->assertSame('en-GB', $english->fresh()->locale);
        $this->assertSame('Custom English', $english->fresh()->title);
        $service->restore($actor, $this->tenant, $english->id, 1, 2);
        $this->assertSame('Member details updated', $english->fresh()->title);
        $this->assertSame(3, $english->fresh()->revision);
        $this->expectException(ValidationException::class);
        $service->save($actor, $this->tenant, $english->id, ['revision' => 1, 'title' => 'Stale', 'body' => 'Stale', 'active' => true]);
    }

    public function test_registration_pushes_render_per_account_and_email_snapshots_recipient_language(): void
    {
        $registration = (new Registration)->forceFill(['id' => 10, 'revision' => 1, 'first_name' => 'Alex', 'last_name' => 'Fixture', 'email' => 'english@example.invalid']);
        app()->setLocale('de');
        $service = app(RegistrationMessageService::class);
        $service->record($this->tenant, $registration, 'registration.created');
        $notifications = DB::connection('clubunio')->table('clubora.notifications')->pluck('title', 'account_id')->all();
        $this->assertSame([1 => 'New membership registration', 2 => 'Neue Mitgliederanmeldung'], $notifications);
        $payload = $service->prepare($this->tenant, $registration, 'registration.received');
        $this->assertSame('en-GB', $payload['locale']);
        $this->assertSame('english@example.invalid', $payload['intended_to']);
        $this->assertSame('en-GB', $payload['deliveries'][0]['locale']);
        $this->assertSame([], $payload['intended_cc']);
    }

    public function test_test_outbox_keeps_guard_and_supports_old_and_localized_payloads(): void
    {
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('raw')->times(3)->andReturnUsing(function ($body, $callback) {
            $mail = \Mockery::mock();
            $mail->shouldReceive('to')->once()->with(TestMailGuard::RECIPIENT)->andReturnSelf();
            $mail->shouldReceive('from')->once()->andReturnSelf();
            $mail->shouldReceive('subject')->once()->andReturnSelf();
            $callback($mail);
            return new \stdClass;
        });
        Mail::shouldReceive('mailer')->times(3)->with('clubunio_test')->andReturn($mailer);
        $db = DB::connection('clubunio');
        foreach ([['title' => 'Legacy', 'body' => 'Legacy'], ['deliveries' => [['title' => 'English', 'body' => 'Hello'], ['title' => 'Deutsch', 'body' => 'Hallo']]]] as $payload) {
            $db->table('clubora.registration_events')->insert(['tenant_id' => 1, 'delivery_state' => 'ready', 'payload_encrypted' => Crypt::encryptString(json_encode($payload))]);
        }
        config(['clubunio-integrations.test_mail_enabled' => false]);
        $dispatcher = app(TestOutboxDispatcher::class);
        $this->assertSame(['accepted' => 0, 'failed' => 0], $dispatcher->dispatch('registration_events'));
        config(['clubunio-integrations.test_mail_enabled' => true]);
        $this->assertSame(['accepted' => 2, 'failed' => 0], $dispatcher->dispatch('registration_events'));
        $this->assertSame(['accepted' => 0, 'failed' => 0], $dispatcher->dispatch('registration_events'));
    }

    public function test_trial_copies_keep_staff_routing_and_their_own_language(): void
    {
        $db = DB::connection('clubunio');
        $db->table('clubora.members')->insert(['id' => 2, 'tenant_id' => 1, 'email' => 'german@example.invalid']);
        $db->table('clubora.team_staff')->insert(['tenant_id' => 1, 'team_season_id' => 5, 'member_id' => 2, 'active' => true]);
        $registration = (new Registration)->forceFill(['id' => 10, 'revision' => 1, 'first_name' => 'Alex', 'email' => 'english@example.invalid', 'team_season_id' => 5]);
        $payload = app(RegistrationMessageService::class)->prepare($this->tenant, $registration, 'registration.trial');
        $this->assertSame('en-GB', $payload['deliveries'][0]['locale']);
        $this->assertSame('de-CH', $payload['deliveries'][1]['locale']);
        $this->assertSame(['german@example.invalid'], $payload['intended_cc']);
        $this->assertSame(['german@example.invalid'], $payload['deliveries'][1]['intended_cc']);
        $this->assertSame([], $payload['deliveries'][1]['intended_to']);
    }

    public function test_expense_review_keeps_tl_and_office_routing_with_localized_snapshots(): void
    {
        $db = DB::connection('clubunio');
        $db->table('clubora.members')->insert(['id' => 2, 'tenant_id' => 1, 'email' => 'german@example.invalid']);
        $db->table('clubora.technical_leads')->insert(['tenant_id' => 1, 'team_season_id' => 5, 'member_id' => 2, 'active' => true]);
        $db->table('clubora.account_member_links')->insert(['tenant_id' => 1, 'account_id' => 2, 'member_id' => 2, 'kind' => 'self', 'state' => 'approved']);
        $db->table('clubora.tenant_accounts')->where('account_id', 2)->update(['portal_enabled' => true]);
        $expense = (new \App\ClubUnio\Models\Expense)->forceFill(['id' => 10, 'revision' => 1, 'member_id' => 2, 'team_season_id' => 5]);
        $expense->setRelation('status', (new \App\ClubUnio\Models\ExpenseStatus)->forceFill(['code' => 'F']));
        foreach (['member', 'season', 'round', 'teamSeason'] as $relation) {
            $expense->setRelation($relation, null);
        }
        $db->transaction(fn () => app(\App\ClubUnio\Services\ExpenseMessageService::class)->record($this->tenant, $expense));
        $row = $db->table('clubora.expense_events')->first();
        $payload = json_decode(Crypt::decryptString($row->payload_encrypted), true);
        $this->assertSame('ready', $row->delivery_state);
        $this->assertSame(['german@example.invalid', 'english@example.invalid'], $payload['intended_to']);
        $this->assertSame(['de-CH', 'en-GB'], array_column($payload['deliveries'], 'locale'));
        $notifications = $db->table('clubora.notifications')->pluck('title', 'account_id')->all();
        $this->assertSame('Expense claim ready for review', $notifications[1]);
        $this->assertSame('Spesenbeleg zur Kontrolle', $notifications[2]);
        $this->assertCount(2, $notifications);
    }

    public function test_partial_test_delivery_failure_is_not_retried_and_empty_snapshot_fails(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true]);
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('raw')->once()->andReturn(new \stdClass);
        $mailer->shouldReceive('raw')->once()->andThrow(new \RuntimeException('Ambiguous transport outcome'));
        Mail::shouldReceive('mailer')->twice()->with('clubunio_test')->andReturn($mailer);
        $db = DB::connection('clubunio');
        foreach ([['deliveries' => [['title' => 'One', 'body' => 'One'], ['title' => 'Two', 'body' => 'Two']]], ['deliveries' => []]] as $payload) {
            $db->table('clubora.registration_events')->insert(['tenant_id' => 1, 'delivery_state' => 'ready', 'payload_encrypted' => Crypt::encryptString(json_encode($payload))]);
        }
        $dispatcher = app(TestOutboxDispatcher::class);
        $this->assertSame(['accepted' => 0, 'failed' => 2], $dispatcher->dispatch('registration_events'));
        $this->assertSame(['accepted' => 0, 'failed' => 0], $dispatcher->dispatch('registration_events'));
        $this->assertSame(2, $db->table('clubora.registration_events')->where('delivery_state', 'failed')->count());
    }

    public function test_disabled_languages_cannot_be_created_or_reactivated_but_history_is_preserved(): void
    {
        $this->assertSame(['de-CH', 'en-GB'], array_keys(MessageTemplateService::locales()));
        $permissions = \Mockery::mock(app(ModulePermissionService::class));
        $permissions->shouldReceive('authorize')->andReturnNull();
        $this->app->instance(ModulePermissionService::class, $permissions);
        $actor = (new Account)->forceFill(['id' => 1]);
        $service = app(MessageTemplateService::class);
        $data = ['event_key' => 'member.change.approved', 'channel' => 'push', 'locale' => 'fr-CH', 'title' => 'Historique', 'body' => 'Texte', 'active' => false];
        try {
            $service->save($actor, $this->tenant, null, $data);
            $this->fail('Disabled language accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('locale', $error->errors());
        }
        $legacy = (new MessageTemplate)->forceFill($data + ['tenant_id' => 1, 'revision' => 1]);
        $legacy->save();
        $service->save($actor, $this->tenant, $legacy->id, array_replace($data, ['revision' => 1, 'title' => 'Historical correction']));
        $this->assertSame('Historical correction', $legacy->fresh()->title);
        $this->assertSame('fr-CH', $legacy->fresh()->locale);
        $this->expectException(ValidationException::class);
        $service->save($actor, $this->tenant, $legacy->id, array_replace($data, ['revision' => 2, 'active' => true]));
    }

    public function test_batch_render_keeps_recipient_keys_and_disabled_variant_with_bounded_queries(): void
    {
        $db = DB::connection('clubunio');
        $db->enableQueryLog();
        $messages = app(MessageTemplateService::class)->renderForAccounts($this->tenant, 'member.change.approved', 'push', [], [1, 2]);
        $this->assertSame([1, 2], array_keys($messages));
        $this->assertSame('en-GB', $messages[1]['locale']);
        $this->assertSame('de-CH', $messages[2]['locale']);
        $this->assertLessThanOrEqual(3, count($db->getQueryLog()));
        $db->disableQueryLog();
        MessageTemplate::where('locale', 'en-GB')->where('event_key', 'member.change.approved')->update(['active' => false]);
        $messages = app(MessageTemplateService::class)->renderForAccounts($this->tenant, 'member.change.approved', 'push', [], [1, 2]);
        $this->assertNull($messages[1]);
        $this->assertSame('de-CH', $messages[2]['locale']);
    }

    public function test_lookup_values_follow_template_language_but_free_text_is_unchanged(): void
    {
        config(['clubunio-lookups.tables.expense_rounds' => 'Rounds', 'clubunio-lookups.en.Vorrunde' => 'First round']);
        $values = MessageEventCatalog::samples();
        $values['runde'] = new \App\ClubUnio\Services\MessageLookupValue('expense_rounds', ['id' => 1, 'tenant_id' => 1, 'name' => 'Vorrunde']);
        $values['mitglied'] = 'Vorrunde';
        app()->setLocale('de');
        $message = app(MessageTemplateService::class)->render($this->tenant, 'expense.review', 'push', $values, 'en');
        $this->assertStringContainsString('from Vorrunde', $message['body']);
        $this->assertStringContainsString('First round', $message['body']);
        MessageTemplate::where('locale', 'en-GB')->where('event_key', 'expense.review')->delete();
        $fallback = app(MessageTemplateService::class)->render($this->tenant, 'expense.review', 'push', $values, 'en');
        $this->assertStringNotContainsString('First round', $fallback['body']);
        $this->assertSame('de-CH', $fallback['locale']);
    }

    public function test_member_change_notifications_localize_without_expanding_recipients(): void
    {
        $request = (new \App\ClubUnio\Models\MemberChangeRequest)->forceFill(['id' => 9, 'requested_by' => 1]);
        $method = new \ReflectionMethod(\App\ClubUnio\Services\MemberChangeService::class, 'notify');
        $method->invoke(app(\App\ClubUnio\Services\MemberChangeService::class), $this->tenant, $request, 'member.change.requested', true);
        $titles = DB::connection('clubunio')->table('clubora.notifications')->pluck('title', 'account_id')->all();
        $this->assertCount(2, $titles);
        $this->assertSame('Member change request submitted', $titles[1]);
        $this->assertSame('Mitgliederänderungsantrag eingereicht', $titles[2]);
        DB::connection('clubunio')->table('clubora.notifications')->delete();
        $method->invoke(app(\App\ClubUnio\Services\MemberChangeService::class), $this->tenant, $request, 'member.change.approved', false);
        $this->assertSame([1 => 'Member details updated'], DB::connection('clubunio')->table('clubora.notifications')->pluck('title', 'account_id')->all());
    }

    public function test_bus_request_and_approval_keep_their_distinct_recipient_routes(): void
    {
        $db = DB::connection('clubunio');
        $db->table('clubora.bus_reservations')->insert(['id' => 5, 'tenant_id' => 1, 'member_id' => 2, 'starts_at' => '2026-11-04 12:00:00']);
        $db->table('clubora.account_member_links')->insert(['tenant_id' => 1, 'account_id' => 2, 'member_id' => 2, 'kind' => 'self', 'state' => 'approved']);
        $db->table('clubora.tenant_accounts')->where('account_id', 2)->update(['portal_enabled' => true]);
        $method = new \ReflectionMethod(\App\ClubUnio\Services\BusReservationService::class, 'notify');
        $service = app(\App\ClubUnio\Services\BusReservationService::class);
        $method->invoke($service, $this->tenant, 5, 'ANGEFRAGT');
        $this->assertSame([1 => 'New bus reservation request'], $db->table('clubora.notifications')->pluck('title', 'account_id')->all());
        $db->table('clubora.notifications')->delete();
        $method->invoke($service, $this->tenant, 5, 'GENEHMIGT');
        $this->assertSame([2 => 'Busreservierung bestätigt'], $db->table('clubora.notifications')->pluck('title', 'account_id')->all());
    }

    public function test_invoice_notification_uses_recipient_language_and_approved_self_link_only(): void
    {
        $db = DB::connection('clubunio');
        $db->table('clubora.account_member_links')->insert([
            ['tenant_id' => 1, 'account_id' => 1, 'member_id' => 2, 'kind' => 'self', 'state' => 'approved'],
            ['tenant_id' => 1, 'account_id' => 2, 'member_id' => 2, 'kind' => 'parent', 'state' => 'approved'],
        ]);
        $db->table('clubora.tenant_accounts')->where('tenant_id', 1)->update(['portal_enabled' => true]);
        $taxes = \Mockery::mock(\App\ClubUnio\Services\InvoiceTaxService::class);
        $taxes->shouldReceive('summary')->once()->andReturn(['total_cents' => 1000]);
        $this->app->instance(\App\ClubUnio\Services\InvoiceTaxService::class, $taxes);
        $invoice = (new \App\ClubUnio\Models\Invoice)->forceFill(['id' => 8, 'member_id' => 2, 'status_id' => 1]);
        $invoice->setRelation('member', null)->setRelation('season', null);
        $method = new \ReflectionMethod(\App\ClubUnio\Services\BexioReconciliationService::class, 'invoiceNotification');
        $method->invoke(app(\App\ClubUnio\Services\BexioReconciliationService::class), $this->tenant, $invoice, 'Offen', false);
        $this->assertSame([1 => 'Invoice updated'], $db->table('clubora.notifications')->pluck('title', 'account_id')->all());
        $this->assertSame('Your invoice has the status Open.', $db->table('clubora.notifications')->value('body'));
    }
}
