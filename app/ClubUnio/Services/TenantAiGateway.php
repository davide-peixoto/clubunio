<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

final class TenantAiGateway
{
    public function respond(Tenant $tenant, string $task, array $payload): \Illuminate\Http\Client\Response
    {
        abort_unless(in_array($task, ['training','assistant','mobile'], true), 422);
        $config = app(TenantAiConfiguration::class);
        $credentials = $config->credentials($tenant);
        $model = $payload['model'] ?? '';
        abort_unless(OpenAiModels::supports($model) && ($payload['store'] ?? null) === false, 503);
        if ($task === 'training') {
            $allowed = app(TenantAiKnowledge::class)->trainingStores($tenant);
            abort_unless(count($payload['tools'] ?? [])===1 && ($payload['tools'][0]['type'] ?? '')==='file_search'
                && ($payload['tool_choice'] ?? '')==='required',422);
            $requested = $payload['tools'][0]['vector_store_ids'] ?? [];
            abort_unless(is_array($requested) && count($requested)>0 && array_diff($requested,$allowed)===[],403);
            $payload['tools'][0]['max_num_results'] = 8;
            $payload['max_tool_calls'] = 1;
        } elseif ($task === 'mobile') {
            abort_if(!empty($payload['tools']),422);
        }
        $maximum = $task === 'training' ? 32000 : ($task === 'assistant' ? 2000 : 1000);
        $payload['max_output_tokens'] = min($maximum, max(1, (int) ($payload['max_output_tokens'] ?? $maximum)));
        abort_if(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 131072, 413);
        $reserve = OpenAiModels::cost($model, 272000, $payload['max_output_tokens']) + ($task === 'training' ? 10_000 : 0);
        $id = (string) Str::uuid();
        DB::connection('clubunio')->transaction(function () use ($tenant, $credentials, $config, $id, $task, $model, $reserve) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $current = $config->credentials($tenant);
            abort_unless($current['fingerprint'] === $credentials['fingerprint'] && $current['revision'] === $credentials['revision'], 409);
            if ($task==='training') app(TenantAiKnowledge::class)->trainingStores($tenant);
            $spent = $config->table('usage')->where('tenant_id',$tenant->id)->where('created_at','>=',now()->startOfMonth())
                ->selectRaw('coalesce(sum(coalesce(actual_microusd,reserved_microusd)),0) as total')->value('total');
            abort_if((int) $spent + $reserve > $current['limit'], 429, trans('tenant_ai.budget_exhausted'));
            $config->table('usage')->insert(['id'=>$id,'tenant_id'=>$tenant->id,'task'=>$task,'mode'=>$credentials['mode'],
                'project_id'=>$credentials['project'],'model'=>$model,'reserved_microusd'=>$reserve]);
        });
        try {
            $response = Http::withToken($credentials['key'])->withHeaders(['OpenAI-Project'=>$credentials['project']])
                ->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout($task === 'training' ? 85 : 35)
                ->post('https://api.openai.com/v1/responses', $payload);
            abort_unless($response->successful() && $response->json('status') === 'completed', 502, trans('tenant_ai.provider_failed'));
            $input = $response->json('usage.input_tokens'); $output = $response->json('usage.output_tokens');
            abort_unless(is_int($input) && is_int($output) && $input >= 0 && $input <= 272000 && $output >= 0 && $output <= $maximum, 502);
            $searches = collect($response->json('output', []))->where('type','file_search_call')->count();
            abort_if($searches > 4, 502);
            $config->table('usage')->where('id',$id)->where('tenant_id',$tenant->id)->update(['state'=>'completed',
                'actual_microusd'=>OpenAiModels::cost($model,$input,$output)+$searches*2500,
                'input_tokens'=>$input,'output_tokens'=>$output,'search_calls'=>$searches]);
            $current = $config->credentials($tenant);
            abort_unless($current['fingerprint'] === $credentials['fingerprint'] && $current['revision'] === $credentials['revision'], 409);
            return $response;
        } catch (\Throwable $error) {
            $config->table('usage')->where('id',$id)->where('tenant_id',$tenant->id)->where('state','running')
                ->update(['state'=>'attention','error_code'=>'provider_uncertain']);
            throw $error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error
                : new \RuntimeException('Tenant AI provider requires attention', 502);
        }
    }
}
