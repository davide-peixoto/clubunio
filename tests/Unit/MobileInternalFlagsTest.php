<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MobileInternalFlagsTest extends TestCase
{
    public function test_internal_features_need_explicit_valid_activation(): void
    {
        $keys = ['CLUBUNIO_MOBILE_EXPENSE_PROVENANCE_ENABLED', 'CLUBUNIO_MOBILE_NATIVE_PUSH_ADMIN_ENABLED'];
        $before = [];
        foreach ($keys as $key) $before[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        try {
            foreach (['true' => true, 'false' => false, 'invalid' => false, '' => false] as $value => $expected) {
                foreach ($keys as $key) { $_ENV[$key] = $value; $_SERVER[$key] = $value; }
                $expense = require dirname(__DIR__, 2).'/config/clubunio-expense-provenance.php';
                $mobile = require dirname(__DIR__, 2).'/config/clubunio-mobile.php';
                $this->assertSame($expected, $expense['enabled']);
                $this->assertSame($expected, $mobile['native_push_admin_enabled']);
            }
        } finally {
            foreach ($before as $key => [$env, $server]) {
                if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
                if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
            }
        }
    }
}
