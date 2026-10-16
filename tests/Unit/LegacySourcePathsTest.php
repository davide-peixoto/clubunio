<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/tools/legacy-source-paths.php';

class LegacySourcePathsTest extends TestCase
{
    public function test_explicit_legacy_root_is_independent_of_checkout_location(): void
    {
        $previous = getenv('CLUBUNIO_LEGACY_BACKEND_ROOT');
        try {
            putenv('CLUBUNIO_LEGACY_BACKEND_ROOT=C:\\Legacy\\backend\\');
            $this->assertSame('C:/Legacy/backend/phpmaker/project.pmp', clubunioLegacyBackendPath('phpmaker\\project.pmp'));
            $this->assertSame('C:/Legacy/backend/application/.env.prod.local', clubunioLegacyBackendPath('application/.env.prod.local'));
        } finally {
            putenv($previous === false ? 'CLUBUNIO_LEGACY_BACKEND_ROOT' : 'CLUBUNIO_LEGACY_BACKEND_ROOT='.$previous);
        }
    }

    public function test_relative_paths_cannot_escape_the_explicit_source_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        clubunioLegacyBackendPath('../another-project/.env');
    }
}
