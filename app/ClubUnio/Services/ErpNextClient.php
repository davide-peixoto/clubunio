<?php

namespace App\ClubUnio\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ErpNextClient
{
    public function find(array $config, string $type, string $reference): ?array
    {
        $list = $this->request($config, 'GET', $type, [
            'fields' => '["name"]',
            'filters' => json_encode([['custom_clubunio_reference', '=', $reference]], JSON_THROW_ON_ERROR),
            'limit_page_length' => 2,
        ]);
        abort_unless(array_is_list($list) && count($list) <= 1, 409, trans('erpnext.errors.identity'));

        return isset($list[0]) ? $this->get($config, $type, (string) ($list[0]['name'] ?? '')) : null;
    }

    public function get(array $config, string $type, string $id): array
    {
        abort_unless($id !== '' && strlen($id) <= 140, 409, trans('erpnext.errors.identity'));

        return $this->request($config, 'GET', $type, id: $id);
    }

    public function create(array $config, string $type, array $data): array
    {
        return $this->request($config, 'POST', $type, $data);
    }

    private function request(array $config, string $method, string $type, array $data = [], ?string $id = null): array
    {
        $origin = app(ErpNextConfiguration::class)->origin();
        abort_unless(($config['origin'] ?? null) === $origin, 409, trans('erpnext.errors.destination'));
        $path = '/api/resource/'.rawurlencode($type).($id === null ? '' : '/'.rawurlencode($id));

        try {
            $response = Http::baseUrl($origin)
                ->acceptJson()
                ->withHeaders(['Authorization' => 'token '.$config['api_key'].':'.$config['api_secret']])
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(10)
                ->send($method, $path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException) {
            abort(503, trans('erpnext.errors.unavailable'));
        }

        $result = $response->json('data');
        abort_unless($response->successful() && is_array($result), 503, trans('erpnext.errors.unavailable'));

        return $result;
    }
}
