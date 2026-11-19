<?php

namespace Tests\Feature;

use App\ClubUnio\Services\MemberChangeService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MemberChangeConflictsTest extends TestCase
{
    public function test_unrelated_changes_are_not_written_or_conflicted(): void
    {
        self::assertSame(['city' => 'New'], MemberChangeService::pendingChanges(
            ['city' => 'Old'], ['city' => 'New'], ['city' => 'Old', 'email' => 'changed@example.invalid']));
    }

    public function test_already_applied_fields_are_omitted_and_empty_values_are_equivalent(): void
    {
        self::assertSame([], MemberChangeService::pendingChanges(
            ['phone_private' => '123', 'country_id' => 1],
            ['phone_private' => null, 'country_id' => '2'],
            ['phone_private' => '', 'country_id' => 2]));
        self::assertSame(['city' => 'New'], MemberChangeService::pendingChanges(
            ['city' => 'Old', 'email' => 'old@example.invalid'],
            ['city' => 'New', 'email' => 'new@example.invalid'],
            ['city' => 'Old', 'email' => 'new@example.invalid']));
    }

    public function test_real_conflict_rejects_the_entire_plan(): void
    {
        $this->expectException(ValidationException::class);
        MemberChangeService::pendingChanges(['city' => 'Old', 'email' => 'old@example.invalid'],
            ['city' => 'New', 'email' => 'new@example.invalid'],
            ['city' => 'Old', 'email' => 'third@example.invalid']);
    }

    public function test_missing_baseline_fails_closed_even_when_target_is_already_present(): void
    {
        $this->expectException(ValidationException::class);
        MemberChangeService::pendingChanges([], ['city' => 'New'], ['city' => 'New']);
    }

    public function test_protected_fields_are_not_a_requestable_delta(): void
    {
        $this->expectException(ValidationException::class);
        MemberChangeService::pendingChanges(['status_id' => 1], ['status_id' => 2], ['status_id' => 1]);
    }
}
