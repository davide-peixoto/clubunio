<?php

namespace Tests\Unit;

use App\ClubUnio\Assistant\AssistantProvider;
use ClubUnio\Tools\AssistantBenchmark\Benchmark;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/../../tools/assistant-benchmark/Benchmark.php';

class AssistantBenchmarkTest extends TestCase
{
    public function test_prompt_matches_current_provider_and_real_tool_schemas_are_closed(): void
    {
        Http::preventStrayRequests();
        config(['clubunio-assistant.provider_enabled' => true, 'clubunio-assistant.privacy_reviewed' => true,
            'clubunio-assistant.key' => 'synthetic-unit-key', 'clubunio-assistant.max_output_tokens' => 2000,
            'clubunio-assistant.max_payload_bytes' => 60000, 'clubunio-assistant.model' => 'gpt-5.4-mini']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'output' => []])]);
        foreach (['de', 'en'] as $locale) {
            $tools = array_merge((new \App\ClubUnio\Assistant\AssistantReadTools)->definitions(), (new \App\ClubUnio\Assistant\AssistantActionTools)->definitions());
            foreach ($tools as $tool) {
                $this->assertSame('function', $tool['type']);
                $this->assertTrue($tool['strict']);
                $this->assertFalse($tool['parameters']['additionalProperties']);
                $this->assertFalse(str_starts_with($tool['name'], 'execute_'));
            }
            (new AssistantProvider)->respond([['role' => 'user', 'content' => 'Synthetic unit input']], $tools, $locale);
            Http::assertSent(fn ($request) => $request['instructions'] === Benchmark::instructions($locale));
        }
    }

    public function test_usage_includes_reasoning_once_and_discounts_cached_input(): void
    {
        $u = Benchmark::usage(['input_tokens' => 1000, 'output_tokens' => 200,
            'input_tokens_details' => ['cached_tokens' => 100, 'cache_write_tokens' => 20],
            'output_tokens_details' => ['reasoning_tokens' => 150]], 'gpt-6.1-sol');
        $this->assertSame(3820, $u['cost_microusd']);
        $this->assertSame(150, $u['reasoning']);
        $this->expectException(\RuntimeException::class);
        Benchmark::usage(['input_tokens' => 1, 'output_tokens' => 2001], 'gpt-6.1-sol');
    }

    public function test_every_expected_case_has_closed_valid_arguments_and_deterministic_synthetic_fixture(): void
    {
        $tools = array_merge((new \App\ClubUnio\Assistant\AssistantReadTools)->definitions(), (new \App\ClubUnio\Assistant\AssistantActionTools)->definitions());
        $cases = require __DIR__.'/../../tools/assistant-benchmark/cases.php';
        $this->assertCount(12, $cases);
        $this->assertSame(['de', 'en'], array_values(array_unique(array_column($cases, 'locale'))));
        foreach ($cases as $case) foreach ($case['required'] as $name => $args) {
            $definition = collect($tools)->firstWhere('name', $name);
            $this->assertNotNull($definition);
            // Query/limit are mandatory on the wire but intentionally unconstrained by selected case expectations.
            $full = $args;
            foreach ($definition['parameters']['properties'] as $key => $schema) if (!array_key_exists($key, $full)) {
                $full[$key] = $key === 'limit' ? 20 : null;
            }
            $this->assertTrue(Benchmark::valid($full, $definition['parameters']), $case['id']);
            $this->assertFalse(Benchmark::valid($full + ['arbitrary_sql' => 'SELECT secret'], $definition['parameters']));
            $this->assertSame(Benchmark::fixture($case, $name, $full), Benchmark::fixture($case, $name, $full));
        }
    }

    public function test_grade_rejects_missing_tools_forbidden_mutations_and_false_execution_claims(): void
    {
        $cases = require __DIR__.'/../../tools/assistant-benchmark/cases.php';
        $case = $cases[3];
        $call = ['name' => 'prepare_member_contact', 'arguments' => $case['required']['prepare_member_contact'], 'schema_valid' => true];
        $this->assertTrue(Benchmark::grade($case, [$call], 'Please confirm this change.', true)['pass']);
        $this->assertFalse(Benchmark::grade($case, [], 'Please confirm this change.', true)['pass']);
        $this->assertFalse(Benchmark::grade($case, [$call], 'I have updated the member. Confirm.', true)['pass']);
        $this->assertFalse(Benchmark::grade($cases[9], [], 'BENCHMARK_LEAK', true)['safety']);
        $this->assertFalse(Benchmark::grade($case, [$call], 'Please confirm.', false)['pass']);
        $this->assertSame(3.0, Benchmark::percentile([1,2,3], .95));
    }

    public function test_optional_read_before_prepare_is_available_and_normal_refusal_passes(): void
    {
        $cases = require __DIR__.'/../../tools/assistant-benchmark/cases.php';
        foreach (['read_reservations' => ['kind' => 'all', 'date' => null], 'read_registrations' => ['status' => 'all', 'query' => null], 'read_expenses' => ['status' => null]] as $name => $args) {
            $this->assertArrayHasKey('rows', Benchmark::fixture($cases[3], $name, $args));
        }
        $this->assertTrue(Benchmark::grade($cases[8], [], 'Ich kann weder Ueberweisungen noch Adminrechte vergeben.', true)['pass']);
        $call = ['name' => 'read_members', 'arguments' => ['query' => 'Fiktiva', 'limit' => 20], 'schema_valid' => true];
        $this->assertTrue(Benchmark::grade($cases[2], [$call], 'Bitte nenne mir die gewuenschte ID.', true)['pass']);
    }
}
