<?php

namespace App\ClubUnio\Services;

final class OpenAiModels
{
    public const TEXT = 'gpt-6-luna';
    public const BACKOFFICE = 'gpt-6.1-terra';
    public const TRAINING = 'gpt-6.1-terra';
    public const COMPLEX = 'gpt-6.2-sol';

    // Standard USD/1M tokens, numerically equal to micro-USD per token.
    private const RATES = [
        self::TEXT => ['input' => .10, 'output' => .50],
        self::BACKOFFICE => ['input' => 1., 'output' => 5.],
        self::COMPLEX => ['input' => 2., 'output' => 10.],
    ];

    public static function supports(mixed $model): bool
    {
        return is_string($model) && isset(self::RATES[$model]);
    }

    public static function upgrade(mixed $model, string $default): mixed
    {
        return $model === null || $model === '' || (is_string($model) && preg_match('/^gpt-[345](?:[.o-]|$)/D', $model))
            ? $default : $model;
    }

    public static function reasoning(string $model): array
    {
        abort_unless(self::supports($model), 503);

        return ['effort' => $model === self::COMPLEX ? 'low' : 'none'];
    }

    public static function cost(string $model, int $input, int $output): int
    {
        abort_unless(self::supports($model), 503);
        abort_unless($input >= 0 && $input <= 1_000_000 && $output >= 0 && $output <= 128_000, 502);
        $rates = self::RATES[$model];
        // Conservative budget accounting: no assumed cache discount; reasoning is
        // already included in output_tokens. No explicit cache writes requested.
        $long = $input > 272_000;

        return (int) ceil($input * $rates['input'] * ($long ? 2 : 1)
            + $output * $rates['output'] * ($long ? 1.5 : 1));
    }
}
