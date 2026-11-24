<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{SignageBrandService, TenantMailSettings, TestMailGuard, TestMailService, TestOutboxDispatcher};
use Illuminate\Support\Facades\{Crypt, DB, Mail, Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowMailThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-integrations.test_mail_enabled' => true, 'clubunio-integrations.pitch_planning_test_mail_enabled' => true,
            'clubunio-integrations.smtp.host' => 'synthetic.invalid', 'clubunio-integrations.from' => 'sender@example.invalid',
            'mail.mailers.clubunio_test' => ['transport' => 'array'], 'app.url' => 'https://dev.example.test',
            'clubunio-domains.hosts' => ['club.example.test' => 'mail-fixture']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->statement("ATTACH DATABASE ':memory:' AS clubora");
        $schema = Schema::connection('clubunio');
        $schema->create('clubora.tenants', function(Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug'); $t->string('default_locale')->default('de');
        });
        DB::connection('clubunio')->table('clubora.tenants')->insert(['id' => 7, 'name' => 'Synthetic club', 'slug' => 'mail-fixture']);
        foreach (['expense_events', 'registration_events', 'pitch_planning_events'] as $table) {
            $schema->create('clubora.'.$table, function(Blueprint $t) {
                $t->id(); $t->integer('tenant_id'); $t->integer('expense_id')->nullable();
                $t->string('delivery_state'); $t->string('action')->nullable(); $t->text('payload_encrypted');
                $t->timestamp('notifications_recorded_at')->nullable(); $t->timestamp('finished_at')->nullable();
                $t->string('error_code')->nullable();
            });
        }
        $this->mock(TenantMailSettings::class)->shouldReceive('replyTo')->with(7)->andReturn('office@example.invalid');
        $this->mock(SignageBrandService::class)->shouldReceive('design')->andReturn([
            'color' => '#c51a39', 'ink' => '#ffffff', 'logo' => '/images/club.png',
        ]);
    }

    public static function deliveries(): array
    {
        $cases = [];
        foreach (['de-CH', 'en-GB'] as $locale) {
            foreach (['expense_events' => '/expenses/42', 'registration_events' => '/registrations', 'pitch_planning_events' => '/pitch-planning'] as $table => $path) {
                $cases[$table.' '.$locale] = [$table, $path, $locale];
            }
        }
        return $cases;
    }

    #[DataProvider('deliveries')]
    public function test_committed_workflow_delivery_has_branded_html_plaintext_and_safe_envelope(string $table, string $path, string $locale): void
    {
        DB::connection('clubunio')->table('clubora.'.$table)->insert(['id' => 1, 'tenant_id' => 7, 'expense_id' => 42,
            'delivery_state' => 'ready', 'action' => 'requested', 'notifications_recorded_at' => now(),
            'payload_encrypted' => Crypt::encryptString(json_encode(['deliveries' => [[
                'title' => 'Synthetic review', 'body' => "Synthetic <script>unsafe</script>\nCHF 12.35", 'locale' => $locale,
                'intended_to' => ['member@example.invalid'],
            ]]], JSON_THROW_ON_ERROR))]);
        $service = app(TestOutboxDispatcher::class);
        $this->assertSame(['accepted' => 1, 'failed' => 0], $service->dispatch($table, 1, 7, 1));
        $mail = Mail::mailer('clubunio_test')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('#c51a39', $mail->getHtmlBody());
        $this->assertStringContainsString('Powered by ClubUnio', $mail->getHtmlBody());
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $mail->getHtmlBody());
        $this->assertStringContainsString('lang="'.substr($locale, 0, 2).'"', $mail->getHtmlBody());
        if (str_starts_with($locale, 'de')) {
            $this->assertStringContainsString("Vorgang \u{00F6}ffnen", $mail->getHtmlBody());
        }
        $this->assertStringContainsString('charset=utf-8', strtolower($mail->toString()));
        $this->assertStringContainsString('https://dev.example.test'.$path, $mail->getHtmlBody());
        $this->assertStringContainsString('CHF 12.35', $mail->getTextBody());
        $this->assertSame(TestMailGuard::RECIPIENT, $mail->getTo()[0]->getAddress());
        $this->assertSame('office@example.invalid', $mail->getReplyTo()[0]->getAddress());
        $this->assertSame([], $mail->getCc()); $this->assertSame([], $mail->getBcc());
        $this->assertStringStartsWith('[ClubUnio TEST] ', $mail->getSubject());
        $this->assertSame(['accepted' => 0, 'failed' => 0], $service->dispatch($table, 1, 7, 1));
        $this->assertCount(1, Mail::mailer('clubunio_test')->getSymfonyTransport()->messages());
    }

    public function test_template_preview_uses_the_same_html_theme_without_resending_a_request(): void
    {
        $schema = Schema::connection('clubunio');
        $schema->create('clubora.message_templates', function(Blueprint $t) {
            $t->id(); $t->integer('tenant_id'); $t->string('event_key'); $t->string('channel'); $t->string('locale');
            $t->string('title'); $t->text('body'); $t->boolean('active'); $t->integer('revision');
        });
        $schema->create('clubora.test_mail_attempts', function(Blueprint $t) {
            $t->id(); $t->integer('tenant_id'); $t->integer('actor_id'); $t->integer('template_id');
            $t->integer('revision'); $t->string('request_id'); $t->string('recipient'); $t->string('status');
            $t->string('error_code')->nullable(); $t->timestamp('created_at'); $t->timestamp('finished_at')->nullable();
        });
        DB::connection('clubunio')->table('clubora.message_templates')->insert(['id' => 4, 'tenant_id' => 7,
            'event_key' => 'expense.review', 'channel' => 'email', 'locale' => 'de-CH', 'title' => 'Synthetic preview',
            'body' => 'Synthetic message', 'active' => true, 'revision' => 1]);
        $schema->create('clubora.accounts', function(Blueprint $t) {
            $t->id(); $t->boolean('active'); $t->boolean('api_only')->default(false);
        });
        $schema->create('clubora.tenant_accounts', function(Blueprint $t) {
            $t->integer('tenant_id'); $t->integer('account_id'); $t->string('role');
        });
        DB::connection('clubunio')->table('clubora.accounts')->insert(['id' => 8, 'active' => true]);
        DB::connection('clubunio')->table('clubora.tenant_accounts')->insert(['account_id' => 8, 'tenant_id' => 7, 'role' => 'admin']);
        $actor = Account::findOrFail(8);
        $tenant = Tenant::findOrFail(7);
        $request = (string)Str::uuid();
        $service = app(TestMailService::class);
        $this->assertSame('accepted', $service->sendPreview($actor, $tenant, 4, $request));
        $this->assertSame('accepted', $service->sendPreview($actor, $tenant, 4, $request));
        $messages = Mail::mailer('clubunio_test')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->sole()->getOriginalMessage();
        $this->assertStringContainsString('#c51a39', $mail->getHtmlBody());
        $this->assertStringContainsString('Powered by ClubUnio', $mail->getHtmlBody());
        $this->assertStringContainsString('Synthetic message', $mail->getTextBody());
    }
}
