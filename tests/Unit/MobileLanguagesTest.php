<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\{MobileLanguages,MobileAiService};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MobileLanguagesTest extends TestCase
{
    public function test_real_central_default_is_de_en_without_enabling_other_implemented_languages(): void
    {
        $this->assertSame(['enabled'=>['de','en'],'default'=>'de'],app(MobileLanguages::class)->policy());
    }
    public function test_implemented_intersection_normalizes_portuguese_alias_and_deduplicates(): void
    {
        config(['clubunio-languages.enabled'=>['en'=>'English','pt'=>'Português','pt-PT'=>'Alias','es'=>'Unavailable','sq'=>'Unavailable'],'clubunio-languages.default'=>'pt']);
        $service=app(MobileLanguages::class);
        $this->assertSame(['enabled'=>['en','pt-PT'],'default'=>'pt-PT'],$service->policy());
        $this->assertSame('pt-PT',$service->validateLanguage('pt'));
        $this->assertSame('pt-PT',$service->validateLanguage('pt-PT'));
        $this->assertFalse($service->effective('pt')['language_fallback']);
        $this->assertSame('pt-PT',$service->effective('fr')['language']);
    }
    public function test_disabled_saved_locale_falls_back_but_absent_preference_is_not_a_warning(): void
    {
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English'],'clubunio-languages.default'=>'de']);
        $service=app(MobileLanguages::class);
        $this->assertSame('de',$service->effective('fr')['language']);
        $this->assertTrue($service->effective('fr')['language_fallback']);
        $this->assertFalse($service->effective(null)['language_fallback']);
        $this->assertSame('en',$service->effective('en')['language']);
    }
    public function test_empty_policy_is_null_and_rejects_every_posted_language(): void
    {
        config(['clubunio-languages.enabled'=>[],'clubunio-languages.default'=>'de']);
        $service=app(MobileLanguages::class);
        $this->assertSame(['enabled'=>[],'default'=>null],$service->policy());
        $this->assertNull($service->effective('de')['language']);
        $this->expectException(ValidationException::class);
        $service->validateLanguage('de');
    }
    public function test_validation_is_fresh_after_central_policy_changes(): void
    {
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English']]);
        $service=app(MobileLanguages::class); $this->assertSame('en',$service->validateLanguage('en'));
        config(['clubunio-languages.enabled'=>['de'=>'Deutsch']]);
        $this->expectException(ValidationException::class); $service->validateLanguage('en');
    }
    public function test_disabled_ai_language_is_rejected_before_any_provider_or_database_work(): void
    {
        config(['clubunio-mobile.ai.enabled'=>true,'clubunio-languages.enabled'=>['de'=>'Deutsch','en'=>'English']]);
        Http::preventStrayRequests();
        try {
            app(MobileAiService::class)->execute(new Account,new Tenant,['request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'operation'=>'translate','language'=>'fr','text'=>'Synthetic draft','consent'=>true]);
            $this->fail('Disabled target accepted');
        } catch (ValidationException $error) { $this->assertArrayHasKey('language',$error->errors()); }
        Http::assertNothingSent();
    }
}
