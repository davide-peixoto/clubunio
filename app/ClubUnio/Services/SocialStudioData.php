<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Competition, Tenant};
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SocialStudioData
{
    public const FORMATS = ['portrait' => 1350, 'square' => 1080];
    public const CHANNELS = ['instagram', 'facebook'];
    public const KINDS = ['day', 'result', 'free'];
    public const FIELDS = ['club', 'date', 'fixtures', 'home', 'away', 'score', 'time', 'place'];

    public function table(int $tenant, string $name): Builder
    {
        return DB::connection('clubunio')->table('clubora.social_'.$name)->where('tenant_id', $tenant);
    }

    public function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function fixtures(Tenant $tenant, string $date): array
    {
        $rows = Competition::query()->where('tenant_id', $tenant->id)->where('active', true)
            ->where('plays_on', $date)->with(['field' => fn ($query) => $query->where('tenant_id', $tenant->id)])
            ->orderBy('starts_at')->orderBy('id')->limit(100)->get();
        $results = $this->table($tenant->id, 'results')->whereIn('competition_id', $rows->modelKeys())->get()->keyBy('competition_id');

        return $rows->map(function ($row) use ($results) {
            $fixture = [
                'id' => (int) $row->id, 'date' => $row->plays_on->toDateString(),
                'time' => substr((string) $row->starts_at, 0, 5),
                'home' => trim($row->host_name.' '.($row->host_category ?? '')),
                'away' => trim($row->guest_name.' '.($row->guest_category ?? '')),
                'place' => (string) ($row->field?->name ?? ''),
            ];
            $result = $results->get($row->id);
            $fixture['score'] = $result && $this->decode($result->fixture) == $fixture
                ? [(int) $result->host_score, (int) $result->guest_score] : null;

            return $fixture;
        })->all();
    }

    public function snapshot(Tenant $tenant, string $kind, string $date, array $ids): array
    {
        if ($kind === 'free') {
            return [];
        }
        $fixtures = collect($this->fixtures($tenant, $date))->keyBy('id');
        $selected = array_map(fn ($id) => $fixtures->get($id), $ids);
        $this->require(count($ids) >= 1 && ! in_array(null, $selected, true), 'matches_required');
        $this->require($kind !== 'result' || (count($selected) === 1 && $selected[0]['score'] !== null), 'result_required');
        if ($kind === 'day') {
            $selected = array_map(fn ($row) => array_diff_key($row, ['score' => true]), $selected);
        }

        return ['date' => $date, 'fixtures' => $selected];
    }

    public function design(int $tenant, array $design): array
    {
        $data = Validator::make(['design' => $design], [
            'design' => 'required|array:format,background,layers',
            'design.format' => ['required', Rule::in(array_keys(self::FORMATS))],
            'design.background' => ['required', 'regex:/^#[a-f0-9]{6}$/i'],
            'design.layers' => 'present|array|max:30',
            'design.layers.*' => 'array:type,x,y,w,h,angle,fill,size,text,field,media,crop,flipX,locked,align,bold',
            'design.layers.*.type' => 'required|in:text,field,rect,image',
            'design.layers.*.x' => 'required|numeric|between:-2160,2160',
            'design.layers.*.y' => 'required|numeric|between:-2700,2700',
            'design.layers.*.w' => 'required|numeric|between:10,2160',
            'design.layers.*.h' => 'required|numeric|between:10,2700',
            'design.layers.*.angle' => 'required|numeric|between:-360,360',
            'design.layers.*.fill' => ['sometimes', 'regex:/^#[a-f0-9]{6}$/i'],
            'design.layers.*.size' => 'sometimes|integer|between:12,180',
            'design.layers.*.text' => 'sometimes|nullable|string|max:1000',
            'design.layers.*.field' => ['sometimes', Rule::in(self::FIELDS)],
            'design.layers.*.media' => 'sometimes|uuid',
            'design.layers.*.crop' => 'sometimes|array:x,y,w,h',
            'design.layers.*.crop.*' => 'required|numeric|between:0,1',
            'design.layers.*.flipX' => 'sometimes|boolean',
            'design.layers.*.locked' => 'sometimes|boolean',
            'design.layers.*.bold' => 'sometimes|boolean',
            'design.layers.*.align' => 'sometimes|in:left,center,right',
        ])->validate()['design'];
        $ids = [];
        foreach ($data['layers'] as $layer) {
            if ($layer['type'] === 'field') {
                $this->require(isset($layer['field']), 'invalid');
            }
            if ($layer['type'] === 'image') {
                $this->require(isset($layer['media']), 'invalid');
                $ids[] = $layer['media'];
                if (isset($layer['crop'])) {
                    $c = $layer['crop'];
                    $this->require(count($c) === 4 && $c['w'] >= 0.01 && $c['h'] >= 0.01
                        && $c['x'] + $c['w'] <= 1.000001 && $c['y'] + $c['h'] <= 1.000001, 'invalid');
                }
            }
        }
        $this->require($this->table($tenant, 'media')->whereIn('id', array_unique($ids))->count() === count(array_unique($ids)), 'invalid_media');

        return $data;
    }

    public function storeImage(int $tenant, UploadedFile $file, bool $export = false, ?int $height = null): string
    {
        $bytes = file_get_contents($file->getPathname());
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        $this->require($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            && $info[0] <= 6000 && $info[1] <= 6000 && $info[0] * $info[1] <= 16000000
            && (! $export || ($info[0] === 1080 && $info[1] === $height)), 'invalid_media');
        $image = @imagecreatefromstring($bytes);
        $this->require($image !== false, 'invalid_media');
        $path = 'social-studio/'.$tenant.'/'.Str::uuid().($export ? '.jpg' : '.png');
        $stream = fopen('php://temp', 'w+b');
        try {
            imagesavealpha($image, true);
            $encoded = $export ? imagejpeg($image, $stream, 91) : imagepng($image, $stream);
            rewind($stream);
            abort_unless($encoded && Storage::disk('local')->put($path, $stream), 503);
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['social' => trans('social.'.$message)]);
        }
    }
}
