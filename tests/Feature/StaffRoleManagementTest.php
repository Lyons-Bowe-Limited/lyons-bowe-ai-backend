<?php

namespace Tests\Feature;

use App\Models\StaffAuditLog;
use App\Models\StaffRole;
use App\Models\StaffUser;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StaffRolesAndPermissionsSeeder::class);
    }

    private function createStaff(string $role): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Test',
            'last_name' => 'Staff',
            'email' => uniqid('staff_').'@example.test',
            'password' => 'TestPassword123!',
            'status' => StaffUser::STATUS_ACTIVE,
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

    public function test_unauthenticated_users_cannot_manage_roles(): void
    {
        $this->getJson('/api/staff/roles')
            ->assertUnauthorized();
    }

    public function test_team_members_cannot_manage_roles(): void
    {
        $member = $this->createStaff('enquiry_team_member');

        $this->authenticate($member);

        $this->getJson('/api/staff/roles')
            ->assertForbidden();
    }

    public function test_super_admin_can_view_roles(): void
    {
        $admin = $this->createStaff('super_admin');

        $this->authenticate($admin);

        $this->getJson('/api/staff/roles')
            ->assertOk()
            ->assertJsonPath('roles.0.name', 'enquiry_team_manager')
            ->assertJsonCount(5, 'roles');
    }

    public function test_super_admin_can_assign_a_role(): void
    {
        $admin = $this->createStaff('super_admin');
        $member = $this->createStaff('enquiry_team_member');

        $this->authenticate($admin);

        $this->putJson("/api/staff/users/{$member->id}/role", [
            'role' => 'solicitor',
        ])->assertOk();

        $this->assertDatabaseHas('staff_role_user', [
            'staff_user_id' => $member->id,
            'staff_role_id' => StaffRole::where('name', 'solicitor')->firstOrFail()->id,
        ]);

        $this->assertDatabaseHas('staff_audit_logs', [
            'staff_user_id' => $admin->id,
            'action' => 'staff.role_updated',
            'subject_type' => StaffUser::class,
            'subject_id' => $member->id,
        ]);
    }

    public function test_super_admin_cannot_change_own_role(): void
    {
        $admin = $this->createStaff('super_admin');

        $this->authenticate($admin);

        $this->putJson("/api/staff/users/{$admin->id}/role", [
            'role' => 'enquiry_team_member',
        ])->assertForbidden();
    }

    public function test_last_active_super_admin_cannot_be_reassigned(): void
    {
        $admin = $this->createStaff('super_admin');
        $secondAdmin = $this->createStaff('super_admin');

        $this->authenticate($admin);

        // Demote one administrator while another remains.
        $this->putJson("/api/staff/users/{$secondAdmin->id}/role", [
            'role' => 'enquiry_team_member',
        ])->assertOk();

        // A sole remaining admin cannot demote themselves.
        $this->putJson("/api/staff/users/{$admin->id}/role", [
            'role' => 'enquiry_team_member',
        ])->assertForbidden();

        $this->assertEquals(
            1,
            StaffUser::where('status', StaffUser::STATUS_ACTIVE)
                ->whereHas('roles', fn ($query) =>
                    $query->where('name', 'super_admin')
                )->count()
        );
    }

    public function test_invalid_roles_are_rejected(): void
    {
        $admin = $this->createStaff('super_admin');
        $member = $this->createStaff('enquiry_team_member');

        $this->authenticate($admin);

        $this->putJson("/api/staff/users/{$member->id}/role", [
            'role' => 'invalid_role',
        ])->assertUnprocessable();
    }


    // NEW: Ensure the final active Super Admin cannot be demoted
    // by another administrator whose account is deactivated.
    public function test_last_active_super_admin_role_is_protected(): void
    {
        $admin = $this->createStaff('super_admin');
        $otherAdmin = $this->createStaff('super_admin');

        // Make the acting admin inactive after establishing authentication.
        // This directly tests the controller safeguard, independently of middleware.
        $this->authenticate($otherAdmin);

        $otherAdmin->update([
            'status' => StaffUser::STATUS_DEACTIVATED,
        ]);

        $this->withoutMiddleware(\App\Http\Middleware\EnsureStaffAccountActive::class);

        $this->putJson("/api/staff/users/{$admin->id}/role", [
            'role' => 'enquiry_team_member',
        ])->assertUnprocessable();

        $this->assertTrue(
            $admin->fresh()->roles()->where('name', 'super_admin')->exists()
        );

        $this->assertDatabaseMissing('staff_audit_logs', [
            'action' => 'staff.role_updated',
            'subject_id' => $admin->id,
        ]);
    }

}
