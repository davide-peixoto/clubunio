<?php

namespace Tests\Unit;

use App\ClubUnio\Resources\FamilyMemberResource;
use App\ClubUnio\Resources\FamilyReportResource;
use App\ClubUnio\Resources\FamilyResource;
use App\ClubUnio\Resources\Relations\FamilyMembersRelationManager;
use PHPUnit\Framework\TestCase;

class FamilyNavigationTest extends TestCase
{
    public function test_only_the_standalone_family_assignment_menu_is_hidden(): void
    {
        $this->assertFalse(FamilyMemberResource::shouldRegisterNavigation());
        $this->assertTrue(FamilyResource::shouldRegisterNavigation());
        $this->assertTrue(FamilyReportResource::shouldRegisterNavigation());
        $this->assertContains(FamilyMembersRelationManager::class, FamilyResource::getRelations());
        $this->assertArrayHasKey('index', FamilyMemberResource::getPages());
    }
}
