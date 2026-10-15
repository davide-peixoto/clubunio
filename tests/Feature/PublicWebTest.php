<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\SignagePreview;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\RegistrationBlobStore;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\SettingsService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PublicWebTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private array $input;

    private array $form;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated DB opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        config(['cache.default' => 'array']);
        $this->tenant = Tenant::create(['slug' => 'public-'.Str::random(10), 'name' => 'Synthetic public club']);
        foreach (['SIGNAGE_PUBLIC_ENABLED', 'REGISTRATION_PUBLIC_ENABLED'] as $key) {
            $this->insert('tenant_settings', ['area' => 'public', 'key' => $key, 'label' => $key, 'enabled' => true, 'value_type' => 'boolean', 'encrypted_value' => Crypt::encryptString('1')]);
        }
        $this->insert('registration_statuses', ['code' => 'submitted', 'name' => 'Eingereicht', 'workflow_key' => 'submitted']);
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Synthetic country']);
        $gender = $this->insert('genders', ['code' => 'N', 'name' => 'Synthetic gender']);
        $billing = $this->insert('billing_recipients', ['code' => 'E', 'name' => 'Synthetic billing', 'email_field' => 'email']);
        $this->input = ['first_name' => 'Synthetic', 'last_name' => 'Public', 'email' => 'synthetic@example.invalid', 'birth_date' => '2013-01-02',
            'city' => 'Synthetic', 'street' => 'Synthetic street', 'postal_code' => '4450', 'country_id' => $country, 'nationality_id' => $country,
            'gender_id' => $gender, 'billing_recipient_id' => $billing, 'consent' => '1', 'request_id' => (string) Str::uuid()];
        $this->form = ['id' => $this->input['request_id'], 'at' => time() - 10];
        app(MessageTemplateService::class)->seed($this->tenant);
        Http::preventStrayRequests();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function submit(array $extra = [])
    {
        return $this->withSession(['public-registration.'.$this->tenant->id => $this->form])
            ->post('/join/'.$this->tenant->slug, array_replace($this->input, $extra));
    }

    public function test_public_pages_and_registered_signage_component_render_without_login(): void
    {
        $this->get('/join/'.$this->tenant->slug)->assertOk()->assertSee('Synthetic country')->assertSee('Testanmeldung absenden')->assertDontSee('synthetic@example.invalid');
        $this->get('/signage/'.$this->tenant->slug)->assertOk()->assertSee('Spielplan heute')->assertSee('Heute keine Spiele')->assertSee('Wird geladen');
        Livewire::test('clubunio.signage-feeds', ['tenantId' => $this->tenant->id, 'publicDisplay' => true])->call('refreshFeeds')->assertSee('Instagram ist nicht aktiviert');
        Http::assertNothingSent();
    }

    public function test_preview_renders_registered_child_component(): void
    {
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic admin', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => 'admin']);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($actor, 'clubunio');
        Filament::setTenant($this->tenant);
        Livewire::test(SignagePreview::class)->assertSee('Signage-Vorschau')->assertSee('Wird geladen')->assertSee('Öffentliche Signage');
    }

    public function test_submission_persists_once_with_private_document_and_workflow_events(): void
    {
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->withArgs(fn ($folder, $name, $bytes, $mime) => str_starts_with($folder, 'clubunio/registrations/'.$this->tenant->id.'/') && Str::isUuid(pathinfo($name, PATHINFO_FILENAME)) && $mime === 'application/pdf');
        $file = UploadedFile::fake()->createWithContent('ausweis.pdf', "%PDF-1.4\nSynthetic test only\n%%EOF");
        $this->submit(['identity' => [$file]])->assertRedirect('/join/'.$this->tenant->slug)->assertSessionHas('submitted', true);
        $r = Registration::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('submitted', $r->status->workflow_key);
        $this->assertNull($r->member_id);
        $this->assertSame('Synthetic street', app(RegistrationDataService::class)->values($r)['street']);
        $this->assertStringNotContainsString('Synthetic street', $r->applicant_encrypted);
        $this->assertSame(1, $this->db->table('clubora.registration_documents')->where('registration_id', $r->id)->count());
        $this->submit()->assertRedirect();
        $this->assertSame(1, Registration::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $this->db->table('clubora.registration_events')->where('registration_id', $r->id)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_invalid_lookup_and_missing_consent_leave_no_registration(): void
    {
        $this->submit(['nationality_id' => 999999999])->assertSessionHasErrors('nationality_id');
        $this->submit(['consent' => '0'])->assertSessionHasErrors('consent');
        $this->assertSame(0, Registration::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_foreign_fields_cannot_set_status_or_membership(): void
    {
        $this->submit(['member_id' => 1, 'status_id' => 1])->assertUnprocessable();
        $this->assertSame(0, Registration::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_disabled_public_surfaces_and_stale_session_are_rejected(): void
    {
        $this->withSession([])->post('/join/'.$this->tenant->slug, $this->input)->assertStatus(419);
        $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->update(['enabled' => false]);
        $this->get('/signage/'.$this->tenant->slug)->assertNotFound();
        $this->get('/join/'.$this->tenant->slug)->assertNotFound();
        $this->submit()->assertNotFound();
    }

    public function test_signage_shows_only_published_games_for_today(): void
    {
        foreach ([['Today public', now('Europe/Zurich')->toDateString(), true], ['Yesterday secret', now('Europe/Zurich')->subDay()->toDateString(), true], ['Hidden game', now('Europe/Zurich')->toDateString(), false]] as [$name, $date, $visible]) {
            $this->insert('competitions', ['host_name' => $name, 'guest_name' => 'Synthetic guest', 'plays_on' => $date, 'show_on_signage' => $visible]);
        }
        $this->get('/signage/'.$this->tenant->slug)->assertOk()->assertSee('Today public')->assertDontSee('Yesterday secret')->assertDontSee('Hidden game');
    }

    public function test_honeypot_and_session_mismatch_are_rejected(): void
    {
        $this->submit(['website' => 'spam'])->assertSessionHasErrors('website');
        $this->submit(['request_id' => (string) Str::uuid()])->assertStatus(419);
        $this->assertSame(0, Registration::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_malformed_request_id_and_excessive_posts_are_rejected(): void
    {
        $this->submit(['request_id' => ['invalid']])->assertSessionHasErrors('request_id');
        for ($i = 0; $i < 4; $i++) {
            $this->submit(['consent' => '0'])->assertSessionHasErrors('consent');
        }
        $this->submit()->assertStatus(429);
        $this->assertSame(0, Registration::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_failed_upload_does_not_leave_successful_registration(): void
    {
        $this->mock(RegistrationBlobStore::class)->shouldReceive('put')->once()->andThrow(new \RuntimeException('Synthetic provider failure'));
        $file = UploadedFile::fake()->createWithContent('ausweis.pdf', "%PDF-1.4\nSynthetic test only\n%%EOF");
        $this->submit(['identity' => [$file]])->assertSessionHasErrors('file');
        $this->assertSame(0, Registration::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, $this->db->table('clubora.public_registration_submissions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_public_feed_mode_cannot_be_tampered_with(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test('clubunio.signage-feeds', ['tenantId' => $this->tenant->id, 'publicDisplay' => true])->set('publicDisplay', false);
    }

    public function test_only_admin_can_change_publication_settings(): void
    {
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic office', 'active' => true, 'password' => bcrypt('fixture')])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => 'office']);
        $id = $this->db->table('clubora.tenant_settings')->where('tenant_id', $this->tenant->id)->where('key', 'SIGNAGE_PUBLIC_ENABLED')->value('id');
        $this->expectException(HttpException::class);
        app(SettingsService::class)->update($actor, $this->tenant, $id, '0');
    }
}
