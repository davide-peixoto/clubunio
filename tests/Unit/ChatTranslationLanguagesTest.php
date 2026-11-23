<?php
namespace Tests\Unit;
use App\ClubUnio\Mobile\{ChatTranslationLanguages,MobileLanguages,MobileAiService};
use App\ClubUnio\Models\{Account,Tenant};
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChatTranslationLanguagesTest extends TestCase
{
    public function test_reviewed_24_chat_targets_are_independent_of_ui_and_have_localized_names(): void
    {
        $this->app->setLocale('de');$policy=app(ChatTranslationLanguages::class)->policy();
        $this->assertCount(24,$policy['enabled']);$this->assertSame('de',$policy['default']);
        $this->assertSame(['de','en'],app(MobileLanguages::class)->policy()['enabled']);
        $this->assertSame($policy['enabled'],array_keys($policy['names']));
        $this->assertSame('Albanisch',$policy['names']['sq']);$this->assertSame('Portugiesisch (Portugal)',$policy['names']['pt-PT']);
        $this->app->setLocale('en');$this->assertSame('Albanian',app(ChatTranslationLanguages::class)->policy()['names']['sq']);
        foreach(['de','en'] as $locale) {
            $this->app->setLocale($locale);
            foreach(app(ChatTranslationLanguages::class)->policy()['names'] as $name) $this->assertStringNotContainsString('chat_translation.',$name);
        }
        foreach(['openai','azure_openai'] as $provider) {
            config(['clubunio-mobile.ai.translation_provider'=>$provider]);$this->assertCount(24,app(ChatTranslationLanguages::class)->policy()['enabled']);
        }
        config(['clubunio-mobile.ai.translation_provider'=>'azure_translator']);
        $this->assertSame(['de','en','fr','it','pt-PT'],app(ChatTranslationLanguages::class)->policy()['enabled']);
    }
    public function test_policy_is_fresh_normalized_deduplicated_and_fail_closed(): void
    {
        $languages=app(ChatTranslationLanguages::class);
        config(['clubunio-chat-translation.enabled'=>['fr','pt','pt-PT','zz',123],'clubunio-chat-translation.default'=>'pt']);
        $this->assertSame(['fr','pt-PT'],$languages->policy()['enabled']);$this->assertSame('pt-PT',$languages->policy()['default']);
        $this->assertSame('pt-PT',$languages->validateLanguage('pt'));
        config(['clubunio-chat-translation.enabled'=>['de']]);
        $this->assertTrue($languages->effective('fr')['language_fallback']);
        $this->assertSame('de',$languages->effective('fr')['language']);
        config(['clubunio-mobile.ai.translation_provider'=>'unreviewed']);
        $this->assertSame([],$languages->policy()['enabled']);$this->assertNull($languages->effective('de')['language']);
        $this->expectException(ValidationException::class);$languages->validateLanguage('de');
    }
    public function test_invalid_chat_language_and_untranslated_writing_language_stop_before_database_or_http(): void
    {
        config(['clubunio-mobile.ai.enabled'=>true]);Http::preventStrayRequests();
        foreach([['translate','zz'],['friendly','sq']] as [$operation,$language]) {
            try { app(MobileAiService::class)->execute(new Account,new Tenant,['request_key'=>(string)\Illuminate\Support\Str::uuid(),
                'operation'=>$operation,'language'=>$language,'text'=>'Synthetic draft','consent'=>true]);$this->fail('Unsupported language accepted'); }
            catch(ValidationException $error){$this->assertArrayHasKey('language',$error->errors());}
        }
        Http::assertNothingSent();
    }
}
