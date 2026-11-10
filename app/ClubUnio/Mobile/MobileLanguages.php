<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\LanguagePreferences;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;

/** Mobile capability intersects the central release policy; no independent enablement. */
final class MobileLanguages
{
    private const IMPLEMENTED = ['de','en','fr','it','pt-PT'];

    public function normalize(?string $language): ?string
    {
        return $language === 'pt' ? 'pt-PT' : $language;
    }

    public function policy(): array
    {
        $enabled=[];
        foreach (array_keys(app(LanguagePreferences::class)->enabled()) as $language) {
            if (!is_string($language)) continue;
            $mobile=$this->normalize($language);
            if (in_array($mobile,self::IMPLEMENTED,true) && !in_array($mobile,$enabled,true)) $enabled[]=$mobile;
        }
        $configured=$this->normalize(config('clubunio-languages.default','de'));
        return ['enabled'=>$enabled,'default'=>in_array($configured,$enabled,true) ? $configured : ($enabled[0] ?? null)];
    }

    public function effective(?string $saved, ?array $policy=null): array
    {
        $policy ??= $this->policy();
        $normal=$this->normalize($saved);
        $allowed=$normal !== null && in_array($normal,$policy['enabled'],true);
        return ['language'=>$allowed ? $normal : $policy['default'],
            'language_fallback'=>$saved !== null && !$allowed,'language_policy'=>$policy];
    }

    public function forAccount(Account $actor): array
    {
        $saved=DB::connection('clubunio')->table('clubunio.mobile_preferences')->where('account_id',$actor->id)->value('language');
        return $this->effective($saved);
    }

    public function rules(): array
    {
        $allowed=$this->policy()['enabled'];
        if (in_array('pt-PT',$allowed,true)) $allowed[]='pt';
        return ['required','string',Rule::in($allowed)];
    }

    public function validateLanguage(mixed $language, string $field='language'): string
    {
        $data=Validator::make([$field=>$language],[$field=>$this->rules()])->validate();
        return $this->normalize($data[$field]);
    }
}
