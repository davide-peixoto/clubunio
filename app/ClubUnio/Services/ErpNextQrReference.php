<?php

namespace App\ClubUnio\Services;

use Brick\Math\BigInteger;

class ErpNextQrReference
{
    public static function fromInvoiceReference(string $reference): string
    {
        $hash = BigInteger::fromBase(hash('sha256', $reference), 16);
        $digits = '9'.str_pad((string) $hash->mod('10000000000000000000000000'), 25, '0', STR_PAD_LEFT);
        $lookup = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = $lookup[($carry + (int) $digit) % 10];
        }

        return $digits.(string) ((10 - $carry) % 10);
    }
}
