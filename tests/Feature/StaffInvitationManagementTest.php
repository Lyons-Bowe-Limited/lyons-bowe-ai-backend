<?php

namespace Tests\Feature;

use App\Mail\StaffActivationMail;
use App\Models\StaffInvitation;
use App\Models\StaffRole;
use App\Models\StaffUser;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffInvitationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StaffRolesAndPermissionsSeeder::class);

        Mail::fake();
    }

    private function createStaff(string $role, string $email): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Test',
            'last_name' => 'Staff',
            'email' => $email,
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

    private function invitationData(): array
    {
        return [
            'first_name' => 'Sarah',
            'last_name' => 'Williams',
            'email' => 'sarah@example.test',
            'role' => 'enquiry_team_member',
        ];
    }

    // Authentication is mandatory.
    public function test_unauthenticated_users_cannot_invite_staff(): void
    {
        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertUnauthorized();
    }

    // Ordinary team members cannot send invitations.
    public function test_team_member_cannot_invite_staff(): void
    {
        $member = $this->createStaff(
            'enquiry_team_member',
            'member@example.test'
        );

        $this->authenticate($member);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertForbidden();

        Mail::assertNothingOutgoing();
    }

    // Staff who can manage the team but not roles must not assign Super Admin.
    public function test_team_manager_cannot_invite_super_admin_without_roles_manage(): void
    {
        $manager = $this->createStaff(
            'enquiry_team_manager',
            'manager@example.test'
        );

        // Simulate a future policy where managers can invite staff.
        $managerRole = StaffRole::where('name', 'enquiry_team_manager')
            ->firstOrFail();

        $teamManagePermission = \App\Models\StaffPermission::where(
            'name',
            'team.manage'
        )->firstOrFail();

        $managerRole->permissions()->syncWithoutDetaching([
            $teamManagePermission->id,
        ]);

        $this->authenticate($manager);

        $this->postJson('/api/staff/invitations', [
            ...$this->invitationData(),
            'role' => 'super_admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('staff_users', [
            'email' => 'sarah@example.test',
        ]);

        $this->assertDatabaseCount('staff_invitations', 0);

        Mail::assertNothingOutgoing();
    }

    // Super Admin can create a pending staff invitation.
    public function test_super_admin_can_invite_staff(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertCreated()
            ->assertJsonPath('staff.email', 'sarah@example.test')
            ->assertJsonPath('staff.status', StaffUser::STATUS_PENDING);

        $this->assertDatabaseHas('staff_users', [
            'email' => 'sarah@example.test',
            'status' => StaffUser::STATUS_PENDING,
            'invited_by' => $admin->id,
        ]);

        Mail::assertSent(StaffActivationMail::class, 1);
    }

    // The selected role must be assigned to the pending account.
    public function test_invitation_assigns_selected_role(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', [
            ...$this->invitationData(),
            'role' => 'solicitor',
        ])->assertCreated();

        $staff = StaffUser::where('email', 'sarah@example.test')->firstOrFail();

        $this->assertTrue(
            $staff->roles()->where('name', 'solicitor')->exists()
        );
    }

    // An existing staff email cannot be invited again.
    public function test_duplicate_staff_email_is_rejected(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->createStaff('enquiry_team_member', 'sarah@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingOutgoing();
    }

    // Only supported staff roles may be assigned.
    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', [
            ...$this->invitationData(),
            'role' => 'invalid_role',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['role']);

        Mail::assertNothingOutgoing();
    }

    // Invitations expire after 24 hours.
    public function test_invitation_expires_after_24_hours(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertCreated();

        $invitation = StaffInvitation::firstOrFail();

        $this->assertEqualsWithDelta(
            24,
            now()->diffInHours($invitation->expires_at),
            0.01
        );
    }

    // Invitation creation must be audited.
    public function test_invitation_creation_is_audited(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertCreated();

        $invitation = StaffInvitation::firstOrFail();

        $this->assertDatabaseHas('staff_audit_logs', [
            'staff_user_id' => $admin->id,
            'action' => 'staff.invitation_created',
            'subject_type' => StaffInvitation::class,
            'subject_id' => $invitation->id,
        ]);
    }

    // The invitation email must use the existing activation template.
    public function test_invitation_sends_existing_activation_email(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertCreated();

        Mail::assertSent(
            StaffActivationMail::class,
            fn (StaffActivationMail $mail) =>
                $mail->hasTo('sarah@example.test')
                && str_contains($mail->activationUrl, '/activate-account?token=')
        );
    }

    // Failed email delivery must roll back the entire invitation.
    public function test_failed_email_delivery_rolls_back_invitation(): void
    {
        $admin = $this->createStaff('super_admin', 'admin@example.test');

        $this->authenticate($admin);

        // Simulate the mail transport throwing an exception.
        Mail::shouldReceive('to')
            ->once()
            ->with('sarah@example.test')
            ->andThrow(new \RuntimeException('Simulated mail transport failure'));

        $this->postJson('/api/staff/invitations', $this->invitationData())
            ->assertInternalServerError()
            ->assertJsonPath(
                'message',
                'The staff invitation could not be sent. Please try again.'
            );

        // No pending staff account should remain.
        $this->assertDatabaseMissing('staff_users', [
            'email' => 'sarah@example.test',
        ]);

        // No invitation or invitation audit record should remain.
        $this->assertDatabaseCount('staff_invitations', 0);

        $this->assertDatabaseMissing('staff_audit_logs', [
            'action' => 'staff.invitation_created',
        ]);
    }

}
