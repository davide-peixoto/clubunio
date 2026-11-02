<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\AzureTranslatorProvider;
use App\ClubUnio\Mobile\OpenAiTextProvider;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MobileTextProvidersTest extends TestCase
{
    public function test_openai_is_stateless_has_no_tools_and_uses_only_the_requested_text(): void
    {
        Http::preventStrayRequests();
        config(['clubunio-mobile.ai.openai.key' => 'synthetic-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => 'Synthetic result']]]], 'usage' => ['input_tokens' => 25, 'output_tokens' => 10]])]);
        $result = (new OpenAiTextProvider)->transform('correct', 'Synthetic input', 'de');
        $this->assertSame('Synthetic result', $result['text']);
        Http::assertSent(fn ($request) => $request['store'] === false && ! isset($request['tools'])
            && json_decode($request['input'], true)['text'] === 'Synthetic input'
            && ! isset($request['previous_response_id']) && $request['max_output_tokens'] <= 1000);
        Http::assertSentCount(1);
    }

    public function test_azure_openai_has_the_same_result_contract_and_uses_the_configured_deployment(): void
    {
        config(['clubunio-mobile.ai.azure_openai' => ['key' => 'synthetic', 'endpoint' => 'https://synthetic.openai.azure.com', 'deployment' => 'reviewed-deployment']]);
        Http::fake(['synthetic.openai.azure.com/openai/v1/responses' => Http::response(['status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => 'Azure result']]]], 'usage' => ['input_tokens' => 30, 'output_tokens' => 8]])]);
        $result = (new OpenAiTextProvider(true))->transform('translate', 'Synthetic', 'pt-PT');
        $this->assertSame(['text', 'model', 'input_tokens', 'output_tokens'], array_keys($result));
        $this->assertSame('reviewed-deployment', $result['model']);
        Http::assertSent(fn ($request) => $request->hasHeader('api-key', 'synthetic') && $request['model'] === 'reviewed-deployment');
    }

    public function test_azure_translator_supports_translation_without_pretending_to_be_a_writing_assistant(): void
    {
        config(['clubunio-mobile.ai.azure_translator.key' => 'synthetic', 'clubunio-mobile.ai.azure_translator.region' => 'synthetic-region']);
        Http::fake(['api.cognitive.microsofttranslator.com/*' => Http::response([['translations' => [['text' => 'Translated result', 'to' => 'pt-pt']]]])]);
        $result = (new AzureTranslatorProvider)->transform('translate', 'Synthetic', 'pt-PT');
        $this->assertSame('Translated result', $result['text']);
        $this->assertNull($result['input_tokens']);
        try { (new AzureTranslatorProvider)->transform('friendly', 'Synthetic', 'de'); $this->fail('Writing must be rejected'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        Http::assertSentCount(1);
    }

    public function test_credential_bearing_requests_cannot_target_arbitrary_azure_urls_or_redirects(): void
    {
        Http::preventStrayRequests();
        foreach (['http://synthetic.openai.azure.com', 'https://other.example', 'https://synthetic.openai.azure.com/path', 'https://user:secret@synthetic.openai.azure.com'] as $url) {
            config(['clubunio-mobile.ai.azure_openai' => ['key' => 'synthetic', 'endpoint' => $url, 'deployment' => 'reviewed']]);
            try { (new OpenAiTextProvider(true))->transform('correct', 'Synthetic', 'de'); $this->fail('Invalid endpoint'); }
            catch (HttpException $e) { $this->assertSame(503, $e->getStatusCode()); }
        }
        Http::assertNothingSent();
    }
}
