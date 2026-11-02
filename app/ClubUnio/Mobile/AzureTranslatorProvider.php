<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Http;

final class AzureTranslatorProvider implements AiTextProvider
{
    public function transform(string $operation, string $text, string $language): array
    {
        abort_unless($operation === 'translate', 422);
        $config = config('clubunio-mobile.ai.azure_translator');
        abort_unless(! empty($config['key']) && ! empty($config['region']), 503);
        // Fixed endpoint; callers cannot turn the backend into a credential-bearing proxy.
        $response = Http::acceptJson()->withHeaders(['Ocp-Apim-Subscription-Key' => $config['key'], 'Ocp-Apim-Subscription-Region' => $config['region']])
            ->connectTimeout(5)->timeout(35)->withoutRedirecting()
            ->post('https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&to='.rawurlencode($language), [['Text' => $text]]);
        abort_unless($response->successful(), 502);
        $result = $response->json('0.translations.0.text');
        abort_unless(is_string($result) && trim($result) !== '' && mb_strlen($result) <= 12000, 502);

        // Character-priced: retain reservation until the operator supplies a reviewed rate.
        return ['text' => $result, 'model' => 'azure-translator', 'input_tokens' => null, 'output_tokens' => null];
    }
}
