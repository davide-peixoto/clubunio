<?php

namespace Tests\Feature;

use App\ClubUnio\Services\PermissionCatalog;
use Tests\TestCase;

class SvsAccessProfileDefinitionsTest extends TestCase
{
    public function test_profiles_use_only_explicit_valid_functions_with_view_dependencies(): void
    {
        $profiles = require base_path('tools/svs-access-profile-definitions.php');
        $this->assertCount(7, $profiles);
        foreach ($profiles as $profile) {
            $this->assertSame([], array_values(array_diff($profile['permissions'], PermissionCatalog::keys())));
            $this->assertSame($profile['permissions'], array_values(array_unique($profile['permissions'])));
            foreach ($profile['permissions'] as $permission) {
                $this->assertContains(explode('.', $permission)[0].'.view', $profile['permissions']);
            }
        }
        $this->assertSame(['facilities.view', 'facilities.update'], $profiles['Platzwart']['permissions']);
        $this->assertEqualsCanonicalizing(PermissionCatalog::readOnly(), $profiles['Geschäftsstelle nur lesen']['permissions']);
        foreach (['Finanzen bearbeiten', 'Geschäftsstelle'] as $name) {
            foreach (['invoices', 'expenses', 'credits'] as $module) {
                foreach (['approve', 'send', 'cancel', 'sync'] as $function) {
                    $this->assertNotContains($module.'.'.$function, $profiles[$name]['permissions']);
                }
            }
        }
        foreach (['approve', 'send', 'cancel', 'sync'] as $function) {
            $this->assertContains('invoices.'.$function, $profiles['Finanzen verantworten']['permissions']);
        }
        $this->assertNotContains('announcements.send', $profiles['Anlagen & Signage']['permissions']);
        $this->assertNotContains('members.update', $profiles['Sportadministration']['permissions']);
    }
}
