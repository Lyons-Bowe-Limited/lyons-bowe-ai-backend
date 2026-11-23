<?php

namespace Tests\Feature;

use App\Models\StaffInvitation;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffUserCountsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $status = 'active', string $role = 'enquiry_team_manager'): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Team', 'last_name' => 'Member', 'email' => Str::uuid().'@example.test',
            'status' => $status, 'email_verified_at' => now(),
        ]);
        $staff->roles()->attach(StaffRole::where('name', $role)->sole());

        return $staff;
    }

    private function invite(StaffUser $staff, array $attributes = []): StaffInvitation
    {
        return StaffInvitation::create([
            'staff_user_id' => $staff->id, 'email' => $staff->email,
            'token_hash' => hash('sha256', Str::uuid()), 'expires_at' => now()->addDay(),
            ...$attributes,
        ]);
    }

    public function test_counts_are_global_and_ignore_tab_search_and_pagination(): void
    {
        $manager = $this->staff();
        $this->staff();
        $this->staff('deactivated');
        $this->invite($this->staff('pending'));
        $this->actingAs($manager, 'staff_sanctum');

        foreach (['', '?status=pending&search=nobody&page=99&per_page=1', '?status=active', '?status=deactivated'] as $query) {
            $this->getJson('/api/staff/users/counts'.$query)->assertOk()
                ->assertExactJson(['total' => 4, 'active' => 2, 'pending' => 1, 'deactivated' => 1]);
        }
    }

    public function test_only_valid_outstanding_invitations_count_as_pending(): void
    {
        $this->freezeTime();
        $manager = $this->staff();
        $this->invite($this->staff('pending'));
        $this->invite($this->staff('pending'), ['expires_at' => now()->subSecond()]);
        $this->invite($this->staff('pending'), ['expires_at' => now()]);
        $this->invite($this->staff('pending'), ['cancelled_at' => now()]);
        $this->invite($this->staff('pending'), ['accepted_at' => now()]);
        $this->staff('pending'); // An account without a valid invitation is not pending work.
        $this->invite($this->staff('deactivated'));
        $this->invite($manager);

        $this->actingAs($manager, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertOk()
            ->assertExactJson(['total' => 3, 'active' => 1, 'pending' => 1, 'deactivated' => 1]);
    }

    public function test_duplicate_invitations_and_active_account_emails_are_not_double_counted(): void
    {
        $manager = $this->staff();
        $pending = $this->staff('pending');
        $this->invite($pending);
        $this->invite($pending, ['email' => strtoupper($pending->email)]);
        $this->invite($this->staff('pending'), ['email' => ' '.strtoupper($manager->email).' ']);

        $this->actingAs($manager, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertOk()
            ->assertExactJson(['total' => 2, 'active' => 1, 'pending' => 1, 'deactivated' => 0]);
    }

    public function test_empty_categories_are_explicit_numeric_zeros_and_super_admin_has_access(): void
    {
        $admin = $this->staff(role: 'super_admin');
        $this->actingAs($admin, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertOk()
            ->assertExactJson(['total' => 1, 'active' => 1, 'pending' => 0, 'deactivated' => 0]);
    }

    public function test_team_view_permission_is_required(): void
    {
        $member = $this->staff(role: 'enquiry_team_member');
        $this->actingAs($member, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertForbidden();
    }

    public function test_unauthenticated_and_client_bearer_requests_are_rejected(): void
    {
        $this->getJson('/api/staff/users/counts')->assertUnauthorized();
        $client = User::factory()->create();
        $this->withToken($client->createToken('client')->plainTextToken)
            ->getJson('/api/staff/users/counts')->assertUnauthorized();
    }

    public function test_inactive_and_unverified_staff_are_blocked(): void
    {
        $inactive = $this->staff('deactivated');
        $this->actingAs($inactive, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertForbidden();
        $unverified = $this->staff();
        $unverified->update(['email_verified_at' => null]);
        $this->actingAs($unverified, 'staff_sanctum')->getJson('/api/staff/users/counts')->assertForbidden();
    }
}
