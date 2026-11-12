<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, MessageTemplate, Tenant};
use App\ClubUnio\Services\{AnnouncementService, MessageEventCatalog, MessageTemplateService, TestMailGuard, TestMailService};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MessageTemplateLanguagesPostgresTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $german;
    private Account $english;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Opt in to synthetic PostgreSQL rollback fixtures.');
        }
        Http::preventStrayRequests();
        Mail::fake();
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'message-language-'.Str::lower(Str::random(16)), 'name' => 'Synthetic language club', 'default_locale' => 'de']);
        foreach (['draft', 'published', 'withdrawn'] as $state) {
            $this->db->table('clubunio.announcement_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $state, 'name' => $state]);
        }
        foreach (['de' => 'german', 'en' => 'english'] as $locale => $property) {
            $account = new Account;
            $account->forceFill(['username' => 'language-'.Str::lower(Str::random(16)).'@example.invalid', 'display_name' => 'Synthetic '.$locale,
                'password' => bcrypt('synthetic-fixture'), 'active' => true, 'preferred_locale' => $locale])->save();
            $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $account->id, 'role' => 'admin']);
            $this->{$property} = $account;
        }
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->german, 'clubunio');
        Filament::setTenant($this->tenant);
        app(MessageTemplateService::class)->seed($this->tenant);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db) && $this->db->transactionLevel()) {
                while ($this->db->transactionLevel()) {
                    $this->db->rollBack();
                }
                if (isset($this->tenant)) {
                    $this->assertFalse($this->db->table('clubunio.tenants')->where('id', $this->tenant->id)->exists(), 'Synthetic tenant must be rolled back');
                    $this->assertFalse($this->db->table('clubunio.message_templates')->where('tenant_id', $this->tenant->id)->exists());
                    $this->assertFalse($this->db->table('clubunio.notifications')->where('tenant_id', $this->tenant->id)->exists());
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function template(string $event, string $locale, string $channel = 'push'): MessageTemplate
    {
        return MessageTemplate::where('tenant_id', $this->tenant->id)->where('event_key', $event)->where('locale', $locale)->where('channel', $channel)->firstOrFail();
    }

    public function test_all_de_en_templates_render_and_seed_preserves_custom_revision(): void
    {
        $service = app(MessageTemplateService::class);
        foreach (['de', 'en'] as $language) {
            app()->setLocale($language === 'de' ? 'en' : 'de');
            foreach (MessageEventCatalog::all($language) as $event => $definition) {
                foreach ($definition['channels'] as $channel) {
                    $message = $service->render($this->tenant, $event, $channel, MessageEventCatalog::samples($language), $language);
                    $this->assertSame($language === 'de' ? 'de-CH' : 'en-GB', $message['locale']);
                    $this->assertStringNotContainsString('{{', $message['body']);
                }
            }
        }
        $german = $this->template('member.change.approved', 'de-CH');
        $service->save($this->german, $this->tenant, $german->id, ['revision' => 1, 'title' => 'Tenant custom title', 'body' => 'Tenant custom body', 'active' => false]);
        $service->seed($this->tenant);
        $this->assertSame('Tenant custom title', $german->fresh()->title);
        $this->assertSame(2, $german->fresh()->revision);
        $this->assertNull($service->renderForAccount($this->tenant, 'member.change.approved', 'push', [], $this->german->id));
        $this->assertSame('Member details updated', $service->renderForAccount($this->tenant, 'member.change.approved', 'push', [], $this->english->id)['title']);
        $service->restore($this->german, $this->tenant, $german->id, 1, 2);
        $this->assertSame(3, $german->fresh()->revision);
        $this->assertTrue($german->fresh()->active);
    }

    public function test_announcement_publishes_per_recipient_and_disabled_variant_blocks_everyone(): void
    {
        $templates = app(MessageTemplateService::class);
        foreach (['de-CH' => 'DE', 'en-GB' => 'EN'] as $locale => $prefix) {
            $template = $this->template('announcement.manual', $locale);
            $templates->save($this->german, $this->tenant, $template->id, ['revision' => 1, 'title' => $prefix.' {{ titel }}', 'body' => '{{ nachricht }}', 'active' => true]);
        }
        $service = app(AnnouncementService::class);
        $draft = fn () => $service->save($this->german, $this->tenant, null, ['title' => 'Fixture', 'body' => 'User text unchanged', 'target_type' => 'accounts', 'target_ids' => [$this->german->id, $this->english->id]]);
        $announcement = $draft();
        $prepared = $service->prepare($this->german, $this->tenant, $announcement->id, 1);
        $this->assertSame(2, $service->publish($this->german, $this->tenant, $announcement->id, 1, $prepared['preview_token']));
        $notifications = $this->db->table('clubunio.notifications')->where('tenant_id', $this->tenant->id)->where('announcement_id', $announcement->id)->pluck('title', 'account_id');
        $this->assertSame('DE Fixture', $notifications[$this->german->id]);
        $this->assertSame('EN Fixture', $notifications[$this->english->id]);
        $blocked = $draft();
        $prepared = $service->prepare($this->german, $this->tenant, $blocked->id, 1);
        $template = $this->template('announcement.manual', 'en-GB');
        $templates->save($this->german, $this->tenant, $template->id, ['revision' => 2, 'title' => $template->title, 'body' => $template->body, 'active' => false]);
        try {
            $service->publish($this->german, $this->tenant, $blocked->id, 1, $prepared['preview_token']);
            $this->fail('Disabled required variant must block the complete announcement');
        } catch (ValidationException) {
            $this->assertSame('draft', $blocked->fresh()->state);
            $this->assertFalse($this->db->table('clubunio.announcement_recipients')->where('announcement_id', $blocked->id)->exists());
            $this->assertFalse($this->db->table('clubunio.notifications')->where('announcement_id', $blocked->id)->exists());
        }
    }

    public function test_changed_recipient_language_invalidates_announcement_preview(): void
    {
        $service = app(AnnouncementService::class);
        $draft = $service->save($this->german, $this->tenant, null, ['title' => 'Fixture', 'body' => 'Unchanged text', 'target_type' => 'accounts', 'target_ids' => [$this->english->id]]);
        $prepared = $service->prepare($this->german, $this->tenant, $draft->id, 1);
        $this->english->forceFill(['preferred_locale' => 'de'])->save();
        try {
            $service->publish($this->german, $this->tenant, $draft->id, 1, $prepared['preview_token']);
            $this->fail('Changed recipient language accepted without a new preview');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->state);
            $this->assertFalse($this->db->table('clubunio.notifications')->where('announcement_id', $draft->id)->exists());
        }
    }

    public function test_testmail_samples_follow_selected_template_and_only_admin_is_addressed(): void
    {
        config(['clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.smtp.host' => 'fixture.invalid', 'clubunio-integrations.from' => 'fixture@example.invalid']);
        $bodies = [];
        $mailer = \Mockery::mock();
        $mailer->shouldReceive('send')->twice()->andReturnUsing(function ($view, $data, $callback) use (&$bodies) {
            $body = $view['raw'];
            $bodies[] = $body;
            $mail = \Mockery::mock();
            $mail->shouldReceive('to')->once()->with(TestMailGuard::RECIPIENT)->andReturnSelf();
            $mail->shouldReceive('from')->once()->with('fixture@example.invalid', 'ClubUnio Test')->andReturnSelf();
            $mail->shouldReceive('subject')->once()->andReturnSelf();
            $callback($mail);

            return new \stdClass;
        });
        Mail::shouldReceive('mailer')->twice()->with('clubunio_test')->andReturn($mailer);
        foreach (['en-GB', 'de-CH'] as $locale) {
            app()->setLocale($locale === 'en-GB' ? 'de' : 'en');
            $template = $this->template('registration.received', $locale, 'email');
            $request = (string) Str::uuid();
            $service = app(TestMailService::class);
            $this->assertSame('accepted', $service->sendPreview($this->german, $this->tenant, $template->id, $request));
            $this->assertSame('accepted', $service->sendPreview($this->german, $this->tenant, $template->id, $request));
        }
        $this->assertStringContainsString('Hello Alex Example', $bodies[0]);
        $this->assertStringContainsString('Example Club', $bodies[0]);
        $this->assertStringContainsString('Guten Tag Alex Beispiel', $bodies[1]);
        $this->assertStringContainsString('Beispielverein', $bodies[1]);
        $this->assertSame([TestMailGuard::RECIPIENT], $this->db->table('clubunio.test_mail_attempts')->where('tenant_id', $this->tenant->id)->distinct()->pluck('recipient')->all());
        $this->assertSame(2, $this->db->table('clubunio.test_mail_attempts')->where('tenant_id', $this->tenant->id)->count());
    }
}
