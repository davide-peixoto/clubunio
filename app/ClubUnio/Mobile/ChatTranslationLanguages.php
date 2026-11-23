<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Provider translation targets; never enables an untranslated app UI locale. */
final class ChatTranslationLanguages
{
    private const LABELS=[
        'de'=>'chat_translation.language_de','en'=>'chat_translation.language_en','fr'=>'chat_translation.language_fr',
        'it'=>'chat_translation.language_it','pt-PT'=>'chat_translation.language_pt_PT','es'=>'chat_translation.language_es',
        'sq'=>'chat_translation.language_sq','bs'=>'chat_translation.language_bs','hr'=>'chat_translation.language_hr',
        'sr'=>'chat_translation.language_sr','tr'=>'chat_translation.language_tr','ar'=>'chat_translation.language_ar',
        'uk'=>'chat_translation.language_uk','ru'=>'chat_translation.language_ru','pl'=>'chat_translation.language_pl',
        'ro'=>'chat_translation.language_ro','nl'=>'chat_translation.language_nl','el'=>'chat_translation.language_el',
        'hu'=>'chat_translation.language_hu','bg'=>'chat_translation.language_bg','cs'=>'chat_translation.language_cs',
        'sk'=>'chat_translation.language_sk','ta'=>'chat_translation.language_ta','fa'=>'chat_translation.language_fa',
    ];

    public function normalize(?string $language): ?string { return $language==='pt'?'pt-PT':$language; }

    public function policy(): array
    {
        $enabled=[];
        $provider=config('clubunio-mobile.ai.translation_provider','openai');
        if(in_array($provider,['openai','azure_openai','azure_translator'],true)) {
            foreach((array)config('clubunio-chat-translation.enabled',[]) as $language) {
                if(!is_string($language)) continue;
                $language=$this->normalize($language);
                // Expanded catalog reviewed for OpenAI-family translation. Azure Translator
                // retains its prior reviewed targets until provider-specific mappings are accepted.
                if($provider==='azure_translator'&&!in_array($language,['de','en','fr','it','pt-PT'],true))continue;
                if(isset(self::LABELS[$language])&&!in_array($language,$enabled,true)) $enabled[]=$language;
            }
        }
        $default=$this->normalize(config('clubunio-chat-translation.default','de'));
        $names=[];
        foreach($enabled as $language) $names[$language]=__(self::LABELS[$language]);
        return ['enabled'=>$enabled,'default'=>in_array($default,$enabled,true)?$default:($enabled[0]??null),'names'=>$names];
    }

    public function effective(?string $saved): array
    {
        $policy=$this->policy();$normal=$this->normalize($saved);
        $allowed=$normal!==null&&in_array($normal,$policy['enabled'],true);
        return ['language'=>$allowed?$normal:$policy['default'],'language_fallback'=>$saved!==null&&!$allowed,'language_policy'=>$policy];
    }

    public function rules(): array
    {
        $allowed=$this->policy()['enabled'];if(in_array('pt-PT',$allowed,true))$allowed[]='pt';
        return ['required','string',Rule::in($allowed)];
    }

    public function validateLanguage(mixed $language,string $field='language'): string
    {
        $data=Validator::make([$field=>$language],[$field=>$this->rules()])->validate();return $this->normalize($data[$field]);
    }
}
