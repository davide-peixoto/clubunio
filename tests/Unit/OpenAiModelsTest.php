<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\OpenAiTextProvider;
use App\ClubUnio\Services\OpenAiModels;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiModelsTest extends TestCase
{
    public function test_defaults_and_legacy_overrides_move_to_task_models(): void
    {
        $this->assertSame('gpt-6.1-terra', config('clubunio-assistant.model'));
        $this->assertSame('gpt-6-luna', config('clubunio-mobile.ai.openai.model'));
        $this->assertSame('gpt-6-luna', config('clubunio-support.model'));
        foreach ([null, '', 'gpt-5.4-mini', 'gpt-5.6-luna', 'gpt-4o'] as $old) {
            $this->assertSame(OpenAiModels::TEXT, OpenAiModels::upgrade($old, OpenAiModels::TEXT));
        }
        $this->assertSame(OpenAiModels::COMPLEX, OpenAiModels::upgrade(OpenAiModels::COMPLEX, OpenAiModels::TEXT));
        $this->assertFalse(OpenAiModels::supports('unreviewed-model'));
        $this->assertFalse(OpenAiModels::supports('gpt-5.4-mini'));
    }

    public function test_costs_and_reasoning_are_model_specific_and_budgeted(): void
    {
        foreach ([OpenAiModels::TEXT => 2000, OpenAiModels::BACKOFFICE => 20000, OpenAiModels::COMPLEX => 40000] as $model => $cost) {
            $this->assertSame($cost, OpenAiModels::cost($model, 10000, 2000));
            $this->assertLessThanOrEqual(config('clubunio-assistant.request_reservation_microusd'), 4 * OpenAiModels::cost($model, 100000, 2000));
            $this->assertSame($model === OpenAiModels::COMPLEX ? 'low' : 'none', OpenAiModels::reasoning($model)['effort']);
        }
        $this->assertSame(605000, OpenAiModels::cost(OpenAiModels::BACKOFFICE, 280000, 6000));
    }

    public function test_mobile_uses_luna_statelessly_without_tools_or_retries(): void
    {
        Http::preventStrayRequests();
        config(['clubunio-mobile.ai.openai.key' => 'synthetic']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            'output' => [['content' => [['type' => 'output_text', 'text' => 'Hello']]]]])]);
        $result = (new OpenAiTextProvider)->transform('translate', 'Hallo', 'en');
        $this->assertSame(OpenAiModels::TEXT, $result['model']);
        Http::assertSent(fn ($request) => $request['model'] === OpenAiModels::TEXT && $request['store'] === false
            && $request['reasoning']['effort'] === 'none' && !isset($request['tools']));
        Http::assertSentCount(1);
    }

    public function test_assistant_sol_uses_supported_reasoning_and_correct_cost(): void
    {
        config(['clubunio-assistant.provider_enabled' => true, 'clubunio-assistant.privacy_reviewed' => true,
            'clubunio-assistant.key' => 'synthetic', 'clubunio-assistant.model' => OpenAiModels::COMPLEX]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100], 'output' => []])]);
        $result = (new \App\ClubUnio\Assistant\AssistantProvider)->respond([], [], 'en');
        $this->assertSame(3000, $result['cost_microusd']);
        Http::assertSent(fn ($request) => $request['reasoning']['effort'] === 'low' && $request['model'] === OpenAiModels::COMPLEX);
        Http::assertSentCount(1);
    }

    public function test_training_retains_strict_retrieval_and_rejects_unreviewed_models(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response([], 503)]);
        $tenant = new \App\ClubUnio\Models\Tenant;
        $provider = ['OPENAI_API_KEY' => 'synthetic', 'OPENAI_TRAINING_VECTOR_STORE_ID' => 'vs_synthetic',
            'OPENAI_TRAINING_MODEL' => OpenAiModels::TRAINING];
        try {
            (new \App\ClubUnio\Services\TrainingPlanAi)->generate($tenant, [], providerConfiguration: $provider);
            $this->fail('Provider failure must remain a failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Training provider error', $error->getMessage());
        }
        Http::assertSent(fn ($request) => $request['model'] === OpenAiModels::TRAINING && $request['store'] === false
            && $request['reasoning']['effort'] === 'none' && $request['tool_choice'] === 'required'
            && $request['tools'][0]['type'] === 'file_search' && $request['text']['format']['strict'] === true);
        Http::assertSentCount(1);
        $provider['OPENAI_TRAINING_MODEL'] = 'unreviewed-model';
        try {
            (new \App\ClubUnio\Services\TrainingPlanAi)->generate($tenant, [], providerConfiguration: $provider);
            $this->fail('Old model must not be silently used');
        } catch (\RuntimeException $error) {
            $this->assertSame('Training model not supported', $error->getMessage());
        }
        Http::assertSentCount(1);
    }
}
