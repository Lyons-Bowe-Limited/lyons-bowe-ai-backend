<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\StaffUser;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StaffRolesAndPermissionsSeeder::class);
    }

    private function createStaff(
        string $role,
        string $email,
        string $status = StaffUser::STATUS_ACTIVE
    ): StaffUser {
        $staff = StaffUser::create([
            'first_name' => 'Test',
            'last_name' => 'Staff',
            'email' => $email,
            'password' => 'TestPassword123!',
            'status' => $status,
            'email_verified_at' => now(),
        ]);

        $staff->roles()->attach(
            StaffRole::where('name', $role)->firstOrFail()
        );

        return $staff;
    }

    private function authenticate(StaffUser $staff): void
    {
        Sanctum::actingAs($staff, ['*'], 'staff_sanctum');
    }

    public function test_unauthenticated_users_cannot_list_staff(): void
    {
        $this->getJson('/api/staff/users')->assertUnauthorized();
    }

    public function test_team_member_cannot_list_staff(): void
    {
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($member);

        $this->getJson('/api/staff/users')->assertForbidden();
    }

    public function test_super_admin_can_list_staff(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($admin);

        $this->getJson('/api/staff/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_manager_can_list_staff(): void
    {
        $manager = $this->createStaff(
            'enquiry_team_manager',
            'manager@example.test'
        );

        $this->authenticate($manager);

        $this->getJson('/api/staff/users')->assertOk();
    }

    public function test_staff_can_be_filtered_by_status(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        $this->createStaff(
            'enquiry_team_member',
            'pending@example.test',
            StaffUser::STATUS_PENDING
        );

        $this->authenticate($admin);

        $this->getJson('/api/staff/users?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_staff_can_be_searched_by_email(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        $this->createStaff(
            'enquiry_team_member',
            'solicitor@example.test'
        );

        $this->authenticate($admin);

        $this->getJson('/api/staff/users?search=solicitor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'solicitor@example.test');
    }

    public function test_super_admin_can_view_staff_details(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($admin);

        $this->getJson("/api/staff/users/{$member->id}")
            ->assertOk()
            ->assertJsonPath('staff.id', $member->id)
            ->assertJsonPath('staff.role', 'enquiry_team_member');
    }

    public function test_team_member_cannot_view_staff_details(): void
    {
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($member);

        $this->getJson("/api/staff/users/{$member->id}")
            ->assertForbidden();
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        $this->authenticate($admin);

        $this->getJson('/api/staff/users?status=invalid')
            ->assertUnprocessable();
    }

    public function test_staff_listing_is_paginated(): void
    {
        $admin = $this->createStaff(
            'super_admin',
            'admin@example.test'
        );

        for ($i = 1; $i <= 5; $i++) {
            $this->createStaff(
                'enquiry_team_member',
                "member{$i}@example.test"
            );
        }

        $this->authenticate($admin);

        $this->getJson('/api/staff/users?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 3);
    }


    // NEW: Verify Super Admin can deactivate another staff account.
    public function test_super_admin_can_deactivate_staff(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff('enquiry_team_member', 'member@example.test');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/deactivate", [
            'reason' => 'Staff member has left the firm.',
        ])->assertOk();

        $this->assertDatabaseHas('staff_users', [
            'id' => $member->id,
            'status' => StaffUser::STATUS_DEACTIVATED,
            'deactivated_by' => $admin->id,
            'deactivation_reason' => 'Staff member has left the firm.',
        ]);

        $this->assertNotNull($member->fresh()->deactivated_at);
    }

    // NEW: Verify all existing access tokens are revoked.
    public function test_deactivation_revokes_all_staff_tokens(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff('enquiry_team_member', 'member@example.test');

        $member->createToken('desktop');
        $member->createToken('mobile');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/deactivate", [
            'reason' => 'Employment terminated.',
        ])->assertOk();

        $this->assertSame(0, $member->tokens()->count());
    }

    // NEW: Verify ordinary staff cannot deactivate accounts.
    public function test_team_member_cannot_deactivate_staff(): void
    {
        $member = $this->createStaff('enquiry_team_member', 'member@example.test');
        $target = $this->createStaff('enquiry_team_member', 'target@example.test');

        $this->authenticate($member);

        $this->patchJson("/api/staff/users/{$target->id}/deactivate", [
            'reason' => 'Attempted unauthorised deactivation.',
        ])->assertForbidden();

        $this->assertSame(StaffUser::STATUS_ACTIVE, $target->fresh()->status);
    }

    // NEW: Verify administrators cannot deactivate themselves.
    public function test_super_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$admin->id}/deactivate", [
            'reason' => 'Attempting self-deactivation.',
        ])->assertForbidden();
    }

    // NEW: Verify the last active Super Admin is protected.
    public function test_last_active_super_admin_cannot_be_deactivated(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $secondAdmin = $this->createStaff('super_admin', 'second@example.test');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$secondAdmin->id}/deactivate", [
            'reason' => 'Administrator leaving the firm.',
        ])->assertOk();

        $this->assertSame(StaffUser::STATUS_DEACTIVATED, $secondAdmin->fresh()->status);
        $this->assertSame(StaffUser::STATUS_ACTIVE, $admin->fresh()->status);
    }

    // NEW: Verify deactivation is recorded in the audit log.
    public function test_deactivation_creates_an_audit_log(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff('enquiry_team_member', 'member@example.test');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/deactivate", [
            'reason' => 'Staff member resigned.',
        ])->assertOk();

        $this->assertDatabaseHas('staff_audit_logs', [
            'staff_user_id' => $admin->id,
            'action' => 'staff.account_deactivated',
            'subject_type' => StaffUser::class,
            'subject_id' => $member->id,
        ]);
    }

    // NEW: Verify a reason is mandatory.
    public function test_deactivation_requires_a_reason(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff('enquiry_team_member', 'member@example.test');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/deactivate", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }


    // NEW: Super Admin can reactivate a previously deactivated staff account.
    public function test_super_admin_can_reactivate_staff(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test',
            StaffUser::STATUS_DEACTIVATED
        );

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Staff account reactivated successfully.');

        $member->refresh();

        $this->assertSame(StaffUser::STATUS_ACTIVE, $member->status);
        $this->assertNull($member->deactivated_at);
        $this->assertNull($member->deactivated_by);
        $this->assertNull($member->deactivation_reason);
    }

    // NEW: Reactivation preserves the staff member's existing role.
    public function test_reactivation_preserves_existing_role(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff(
            'solicitor',
            'solicitor@example.test',
            StaffUser::STATUS_DEACTIVATED
        );

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/reactivate")
            ->assertOk();

        $this->assertTrue(
            $member->fresh()->roles()->where('name', 'solicitor')->exists()
        );
    }

    // NEW: Ordinary staff cannot reactivate accounts.
    public function test_team_member_cannot_reactivate_staff(): void
    {
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $target = $this->createStaff(
            'enquiry_team_member',
            'target@example.test',
            StaffUser::STATUS_DEACTIVATED
        );

        $this->authenticate($member);

        $this->patchJson("/api/staff/users/{$target->id}/reactivate")
            ->assertForbidden();

        $this->assertSame(
            StaffUser::STATUS_DEACTIVATED,
            $target->fresh()->status
        );
    }

    // NEW: Active accounts cannot be reactivated again.
    public function test_active_staff_cannot_be_reactivated(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/reactivate")
            ->assertUnprocessable();
    }

    // NEW: Reactivation must create an audit record.
    public function test_reactivation_creates_an_audit_log(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test',
            StaffUser::STATUS_DEACTIVATED
        );

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/reactivate")
            ->assertOk();

        $this->assertDatabaseHas('staff_audit_logs', [
            'staff_user_id' => $admin->id,
            'action' => 'staff.account_reactivated',
            'subject_type' => StaffUser::class,
            'subject_id' => $member->id,
        ]);
    }

    // NEW: Reactivation must not issue or retain old access tokens.
    public function test_reactivation_revokes_existing_tokens(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test',
            StaffUser::STATUS_DEACTIVATED
        );

        $member->createToken('old-session');

        $this->authenticate($admin);

        $this->patchJson("/api/staff/users/{$member->id}/reactivate")
            ->assertOk();

        $this->assertSame(0, $member->tokens()->count());
    }


}
