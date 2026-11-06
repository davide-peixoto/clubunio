<?php

namespace Tests\Unit;

use App\ClubUnio\Assistant\AssistantProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistantProviderTest extends TestCase
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['clubunio-assistant.provider_enabled' => true, 'clubunio-assistant.privacy_reviewed' => true,
            'clubunio-assistant.key' => 'synthetic-assistant-key', 'clubunio-assistant.model' => 'gpt-5.4-mini',
            'clubunio-assistant.max_payload_bytes' => 60000, 'clubunio-assistant.max_output_tokens' => 2000]);
    }

    private function tools(): array
    {
        return [['type' => 'function', 'name' => 'read_members', 'description' => 'Synthetic member lookup',
            'strict' => true, 'parameters' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                'required' => ['query', 'limit'], 'additionalProperties' => false]]];
    }

    private function response(array $replace = []): array
    {
        return array_replace(['status' => 'completed', 'usage' => ['input_tokens' => 11, 'output_tokens' => 3],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Synthetic answer']]]]], $replace);
    }

    private function respond(string $text = 'Synthetic question', string $locale = 'en'): array
    {
        return (new AssistantProvider)->respond([['role' => 'user', 'content' => $text]], $this->tools(), $locale);
    }

    private function assertStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('The provider must reject this request/response.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public static function closedGates(): array
    {
        return [
            'disabled' => ['provider_enabled', false], 'unreviewed' => ['privacy_reviewed', false],
            'string enabled' => ['provider_enabled', 'true'], 'string reviewed' => ['privacy_reviewed', 'true'],
            'missing key' => ['key', null], 'empty key' => ['key', ''], 'nonstring key' => ['key', 123],
        ];
    }

    #[DataProvider('closedGates')]
    public function test_every_provider_gate_is_required_before_http(string $key, mixed $value): void
    {
        config(['clubunio-assistant.'.$key => $value]);
        Http::fake();
        $this->assertFalse((new AssistantProvider)->enabled());
        try {
            $this->respond();
            $this->fail('Closed provider gates must reject the request.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assistant', $e->errors());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('locales')]
    public function test_exact_origin_stateless_strict_tools_and_bounded_transport(string $locale, string $language): void
    {
        config(['clubunio-assistant.endpoint' => 'https://untrusted.invalid/responses',
            'clubunio-assistant.max_output_tokens' => 99999]);
        $options = null;
        Http::fake([self::ENDPOINT => function ($request, $requestOptions) use (&$options) {
            $options = $requestOptions;
            return Http::response($this->response());
        }]);
        $this->respond('Synthetic question', $locale);
        Http::assertSent(function ($request) use ($language) {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic-assistant-key'));
            $this->assertFalse($request['store']);
            $this->assertFalse($request['parallel_tool_calls']);
            $this->assertSame(2000, $request['max_output_tokens']);
            $this->assertSame('gpt-5.4-mini', $request['model']);
            $this->assertSame($this->tools(), $request['tools']);
            $this->assertStringContainsString('Reply in '.$language, $request['instructions']);
            $this->assertArrayNotHasKey('previous_response_id', $request->data());
            $this->assertArrayNotHasKey('conversation', $request->data());
            return true;
        });
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(25, $options['timeout']);
        Http::assertSentCount(1);
    }

    public static function locales(): array
    {
        return ['English' => ['en', 'English'], 'German' => ['de', 'German']];
    }

    public function test_unreviewed_model_is_rejected_without_http(): void
    {
        config(['clubunio-assistant.model' => 'unreviewed-model']);
        Http::fake();
        $this->assertStatus(503, fn () => $this->respond());
        Http::assertNothingSent();
    }

    public function test_response_schema_calls_and_fractional_cost_round_up(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['output' => [
            ['type' => 'function_call', 'name' => 'read_members', 'call_id' => 'call-synthetic',
                'arguments' => '{"query":"Synthetic","limit":2}'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'First '],
                ['type' => 'output_text', 'text' => 'second'], ['type' => 'refusal', 'refusal' => 'Ignored']]],
        ]]))]);
        $this->assertSame(['text' => 'First second', 'calls' => [['name' => 'read_members',
            'arguments' => ['query' => 'Synthetic', 'limit' => 2], 'call_id' => 'call-synthetic']],
            'input_tokens' => 11, 'output_tokens' => 3, 'cost_microusd' => 22], $this->respond());
        Http::assertSentCount(1);
    }

    public static function invalidUsage(): array
    {
        return ['missing' => [null], 'missing output' => [['input_tokens' => 1]],
            'negative input' => [['input_tokens' => -1, 'output_tokens' => 1]],
            'negative output' => [['input_tokens' => 1, 'output_tokens' => -1]],
            'string' => [['input_tokens' => '1', 'output_tokens' => 1]],
            'fraction' => [['input_tokens' => 1, 'output_tokens' => 1.5]],
            'boolean' => [['input_tokens' => true, 'output_tokens' => 1]],
            'input above cap' => [['input_tokens' => 100001, 'output_tokens' => 1]],
            'output above cap' => [['input_tokens' => 1, 'output_tokens' => 2001]],
            'overflow' => [['input_tokens' => PHP_INT_MAX, 'output_tokens' => PHP_INT_MAX]]];
    }

    #[DataProvider('invalidUsage')]
    public function test_invalid_usage_cannot_be_billed(mixed $usage): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['usage' => $usage]))]);
        $this->assertStatus(502, fn () => $this->respond());
        Http::assertSentCount(1);
    }

    public function test_zero_usage_has_zero_cost(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['usage' => ['input_tokens' => 0, 'output_tokens' => 0]]))]);
        $this->assertSame(0, $this->respond()['cost_microusd']);
    }

    public function test_maximum_accepted_usage_cost_stays_within_one_round_reservation(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['usage' => ['input_tokens' => 100000, 'output_tokens' => 2000]]))]);
        $cost = $this->respond()['cost_microusd'];
        $this->assertSame(84000, $cost);
        $this->assertLessThanOrEqual(400000, 4 * $cost);
    }

    public static function failedResponses(): array
    {
        return ['redirect' => [302, 'completed'], 'rate limit' => [429, 'completed'],
            'server error' => [503, 'completed'], 'incomplete' => [200, 'incomplete'],
            'failed' => [200, 'failed']];
    }

    #[DataProvider('failedResponses')]
    public function test_failed_or_redirected_response_is_never_retried(int $status, string $state): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['status' => $state]), $status,
            ['Location' => 'https://untrusted.invalid/credentials'])]);
        $this->assertStatus(502, fn () => $this->respond());
        Http::assertSentCount(1);
    }

    public function test_timeout_is_never_retried(): void
    {
        $attempts = 0;
        Http::fake([self::ENDPOINT => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout');
        }]);
        try {
            $this->respond();
            $this->fail('A timeout must not become a successful response.');
        } catch (ConnectionException $e) {
            $this->assertSame('Synthetic timeout', $e->getMessage());
        }
        $this->assertSame(1, $attempts);
    }

    public static function hugePayloads(): array
    {
        return ['configured bound' => [2000, 2001], 'hard bound' => [1000000, 60001],
            'utf8 bytes' => [60000, 31000]];
    }

    #[DataProvider('hugePayloads')]
    public function test_payload_byte_limit_is_enforced_before_http(int $limit, int $length): void
    {
        config(['clubunio-assistant.max_payload_bytes' => $limit]);
        Http::fake();
        $this->assertStatus(413, fn () => $this->respond(str_repeat("\u{00e4}", $length)));
        Http::assertNothingSent();
    }

    public static function malformedCalls(): array
    {
        $call = ['type' => 'function_call', 'name' => 'read_members', 'call_id' => 'synthetic', 'arguments' => '{}'];
        return ['name missing' => [[array_diff_key($call, ['name' => true])]],
            'call id invalid' => [[array_replace($call, ['call_id' => 1])]],
            'arguments scalar' => [[array_replace($call, ['arguments' => '123'])]],
            'too many calls' => [array_fill(0, 5, $call)]];
    }

    #[DataProvider('malformedCalls')]
    public function test_invalid_tool_response_schema_is_rejected(array $output): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['output' => $output]))]);
        $this->assertStatus(502, fn () => $this->respond());
    }

    public function test_oversized_answer_is_rejected(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response(['output' => [['type' => 'message',
            'content' => [['type' => 'output_text', 'text' => str_repeat('x', 16001)]]]]]))]);
        $this->assertStatus(502, fn () => $this->respond());
    }

    public static function unrestrictedTools(): array
    {
        return ['web search' => [[['type' => 'web_search']]],
            'remote MCP' => [[['type' => 'mcp', 'server_label' => 'untrusted', 'server_url' => 'https://untrusted.invalid']]],
            'code interpreter' => [[['type' => 'code_interpreter', 'container' => ['type' => 'auto']]]],
            'nonstrict function' => [[['type' => 'function', 'name' => 'read_members', 'strict' => false,
                'parameters' => ['type' => 'object', 'additionalProperties' => false]]]],
            'unrestricted properties' => [[['type' => 'function', 'name' => 'read_members', 'strict' => true,
                'parameters' => ['type' => 'object', 'additionalProperties' => true]]]]];
    }

    #[DataProvider('unrestrictedTools')]
    public function test_unrestricted_tools_are_rejected_before_http(array $tools): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->response())]);
        try {
            (new AssistantProvider)->respond([['role' => 'user', 'content' => 'Synthetic']], $tools, 'en');
        } catch (HttpException|ValidationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        Http::assertNothingSent();
    }
}
