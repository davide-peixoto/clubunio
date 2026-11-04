<?php

namespace Tests\Feature;

use App\ClubUnio\Services\LanguagePreferences;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LanguagePreferencesTest extends TestCase
{
    public function test_only_enabled_languages_are_selected_with_german_fallback(): void
    {
        $service = app(LanguagePreferences::class);
        $this->assertSame('de',$service->resolve());
        $this->assertSame('en',$service->resolve('en','de'));
        $this->assertSame('de',$service->resolve('fr',null,'de'));
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch']]);
        $this->assertSame('de',$service->resolve('en'));
    }

    public function test_switch_returns_only_to_safe_same_host_paths(): void
    {
        $service = app(LanguagePreferences::class);
        $this->assertSame('/settings?tab=general',$service->returnPath('https://dev.clubunio.ch/settings?tab=general','dev.clubunio.ch'));
        foreach (['https://evil.example/settings','https://dev.clubunio.ch//evil.example','https://dev.clubunio.ch/%2fexample.com','https://dev.clubunio.ch/\\evil.example',null] as $url) {
            $this->assertSame('/',$service->returnPath($url,'dev.clubunio.ch'));
        }
    }

    public function test_guest_can_switch_but_cannot_select_unknown_language(): void
    {
        $this->post('/language',['locale'=>'en'])->assertRedirect('/')->assertSessionHas('clubunio.locale','en');
        $this->post('/language',['locale'=>'xx'])->assertSessionHasErrors('locale');
        $this->withSession(['clubunio.locale'=>'en'])->get('/login')->assertOk()->assertHeader('Content-Language','en')->assertSee('Username')->assertSee('English');
        $this->withSession(['clubunio.locale'=>'de'])->get('/login')->assertOk()->assertHeader('Content-Language','de')->assertSee('Benutzername');
    }

    public function test_catalogues_and_filament_labels_switch_without_changing_data(): void
    {
        app()->setLocale('en');
        $this->assertSame('Members',__('Mitglieder'));
        $this->assertSame('Welcome, Davide',__('Willkommen, :name',['name'=>'Davide']));
        $this->assertSame('Username',\Filament\Forms\Components\TextInput::make('username')->label('Benutzername')->getLabel());
        $this->assertSame('Member',\App\ClubUnio\Resources\MemberResource::getModelLabel());
        app()->setLocale('de');
        $this->assertSame('Mitglieder',__('Mitglieder'));
        $this->assertSame('Unbekannter Freitext',__('Unbekannter Freitext'));
    }

    public function test_preference_changes_only_current_account_and_leaves_permissions_unchanged(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Rollback DB fixtures require opt-in'); }
        $db = DB::connection('clubunio');
        $db->beginTransaction();
        try {
            $account = new \App\ClubUnio\Models\Account;
            $account->forceFill(['username'=>'locale-'.bin2hex(random_bytes(8)), 'display_name'=>'Language fixture','password'=>bcrypt('fixture-password'),'active'=>true])->save();
            $before = $account->getAttributes();
            $this->actingAs($account,'clubunio')->post('/language',['locale'=>'en','account_id'=>1,'role'=>'admin'])->assertRedirect('/');
            $this->assertSame('en',$account->fresh()->preferred_locale);
            $this->assertSame($before['password'],$account->fresh()->password);
            $this->assertSame(0,$account->tenants()->count());
            $this->withSession(['clubunio.locale'=>'de'])->get('/login')->assertHeader('Content-Language','en');
        } finally { $db->rollBack(); }
    }

    public function test_club_default_is_admin_only_audited_and_does_not_override_personal_choice(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Rollback DB fixtures require opt-in'); }
        $db = DB::connection('clubunio');
        $db->beginTransaction();
        try {
            $tenant = \App\ClubUnio\Models\Tenant::create(['slug'=>'language-'.bin2hex(random_bytes(6)),'name'=>'Language fixture']);
            $actor = new \App\ClubUnio\Models\Account;
            $actor->forceFill(['username'=>'lang-'.bin2hex(random_bytes(8)),'display_name'=>'Test','password'=>bcrypt('fixture'),'active'=>true])->save();
            $actor->tenants()->attach($tenant,['role'=>'admin']);
            $service = app(LanguagePreferences::class);
            $input = ['locale'=>'en','expected'=>null,'reason'=>'Language acceptance fixture'];
            $service->setTenantDefault($actor,$tenant,$input);
            $this->assertSame('en',$tenant->fresh()->default_locale);
            $this->assertSame(1,$db->table('clubora.object_changes')->where('tenant_id',$tenant->id)->count());
            try { $service->setTenantDefault($actor,$tenant,$input); $this->fail('Stale value accepted'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('locale',$e->errors()); }
            $this->actingAs($actor,'clubunio')->get('/settings')->assertOk()->assertSee('Club language')->assertHeader('Content-Language','en');
            $this->withSession(['clubunio.locale'=>'de'])->get('/settings')->assertOk()->assertHeader('Content-Language','de');
            $actor->tenants()->updateExistingPivot($tenant,['role'=>'office']);
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            $service->setTenantDefault($actor,$tenant,['expected'=>'en']+$input);
        } finally { $db->rollBack(); }
    }
}
