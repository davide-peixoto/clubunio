<?php

namespace Tests\Unit;

use App\ClubUnio\Services\KeycloakMemberMatch;
use PHPUnit\Framework\TestCase;

final class KeycloakMemberMatchTest extends TestCase
{
    public function test_member_and_minor_children_share_one_login_candidate(): void
    {
        $this->assertSame(['member_id' => 1, 'child_ids' => [2, 3]], $this->match([
            $this->member(1, ['email' => ' qa@example.invalid ']),
            $this->member(3, ['birth_date' => '2008-11-17']),
            $this->member(2, ['birth_date' => '2014-01-01']),
            $this->member(4, ['birth_date' => '2008-11-16']),
            $this->member(5, ['birth_date' => '2030-01-01']),
        ]));
    }

    public function test_duplicate_member_and_conflicting_parent_fail_closed(): void
    {
        $this->assertNull($this->match([
            $this->member(1, ['email' => 'qa@example.invalid']),
            $this->member(2, ['email' => 'qa@example.invalid']),
        ]));
        $this->assertNull($this->match([
            $this->member(1, ['birth_date' => '2014-01-01']),
            $this->member(2, ['birth_date' => '2014-01-01', 'mother_name' => 'Another Parent']),
        ]));
    }

    public function test_leap_day_child_is_not_a_candidate_at_the_age_boundary(): void
    {
        $row = $this->member(1, ['birth_date' => '2008-02-29']);
        $this->assertNull((new KeycloakMemberMatch)->classify([$row], 'qa@example.invalid', 'Sample', 'Parent', '2026-02-28'));
    }

    private function match(array $rows): ?array
    {
        return (new KeycloakMemberMatch)->classify($rows, 'QA@example.invalid', 'Sample', 'Parent', '2026-11-16');
    }

    private function member(int $id, array $fields): object
    {
        return (object) ($fields + ['id' => $id, 'first_name' => 'Sample', 'last_name' => 'Parent', 'email' => null,
            'mother_name' => 'Parent Sample', 'mother_email' => 'qa@example.invalid',
            'father_name' => null, 'father_email' => null, 'birth_date' => '1980-01-01']);
    }
}
