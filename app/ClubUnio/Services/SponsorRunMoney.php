<?php

namespace App\ClubUnio\Services;

use Closure;
use Illuminate\Support\Facades\Validator;

/** Web amounts are CHF; the domain contract remains integer cents. */
class SponsorRunMoney
{
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::parse($value) === null) {
                $fail('Bitte einen CHF-Betrag zwischen 0.01 und 100000.00 mit höchstens zwei Nachkommastellen eingeben.');
            }
        };
    }

    private static function parse(mixed $value): ?int
    {
        if (! is_scalar($value) || is_bool($value)
            || ! preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/D', trim((string) $value), $parts)) {
            return null;
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $cents >= 1 && $cents <= 10000000 ? $cents : null;
    }

    public static function fromWeb(array $input): array
    {
        $data = Validator::make($input, [
            'amount_chf' => ['required', self::rule()],
            'cap_chf' => ['nullable', self::rule()],
        ], [], ['amount_chf' => 'Betrag (CHF)', 'cap_chf' => 'Obergrenze (CHF)'])->validate();
        // Never trust a separately submitted cents field alongside the CHF input.
        $input['amount_cents'] = self::parse($data['amount_chf']);
        $input['cap_cents'] = isset($data['cap_chf']) && $data['cap_chf'] !== '' ? self::parse($data['cap_chf']) : null;
        unset($input['amount_chf'], $input['cap_chf']);

        return $input;
    }

    public static function format(int|string|null $cents): string
    {
        if ($cents === null) {
            return '-';
        }
        $cents = (int) $cents;

        return 'CHF '.number_format(intdiv($cents, 100), 0, '.', "'").'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
