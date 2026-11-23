<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffActivityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $attributes = []): StaffUser
    {
        $staff = StaffUser::create([
            'first_name' => 'Activity', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test',
            'password' => 'StrongPassword!42', 'status' => 'active', 'email_verified_at' => now(),
            ...$attributes,
        ]);
        $staff->roles()->attach(StaffRole::where('name', 'super_admin')->sole());

        return $staff;
    }

    public function test_successful_login_updates_login_time_but_failed_login_preserves_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $staff = $this->staff();
        $this->postJson('/api/staff/auth/login', ['email' => $staff->email, 'password' => 'incorrect'])
            ->assertUnprocessable();
        $this->assertNull($staff->fresh()->last_login_at);
        $this->postJson('/api/staff/auth/login', ['email' => $staff->email, 'password' => 'StrongPassword!42'])
            ->assertOk();
        $loginAt = $staff->fresh()->last_login_at;
        $this->assertTrue($loginAt->equalTo(now()));
        $this->travel(10)->minutes();
        $this->postJson('/api/staff/auth/login', ['email' => $staff->email, 'password' => 'incorrect'])
            ->assertUnprocessable();
        $this->assertTrue($staff->fresh()->last_login_at->equalTo($loginAt));
    }

    public function test_staff_activity_is_throttled_and_does_not_change_account_update_or_login_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $staff = $this->staff();
        $updatedAt = $staff->updated_at;
        $this->actingAs($staff, 'staff_sanctum')->getJson('/api/staff/auth/me')->assertOk()
            ->assertJsonPath('staff.last_active_at', now()->toISOString());
        $firstActivity = $staff->fresh()->last_active_at;
        $this->assertTrue($firstActivity->equalTo(now()));

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'last_active_at')) {
                $writes[] = $query->sql;
            }
        });
        $this->travel(4)->minutes();
        $this->getJson('/api/staff/auth/me')->assertOk();
        $this->travel(1)->minutes();
        $this->getJson('/api/staff/auth/me')->assertOk();
        $this->assertCount(0, $writes);
        $this->assertTrue($staff->fresh()->last_active_at->equalTo($firstActivity));

        $this->travel(1)->seconds();
        $this->getJson('/api/staff/auth/me')->assertOk();
        $this->assertCount(1, $writes);
        $this->assertTrue($staff->fresh()->last_active_at->equalTo(now()));
        $this->assertTrue($staff->fresh()->updated_at->equalTo($updatedAt));
        $this->assertNull($staff->fresh()->last_login_at);
    }

    public function test_unauthenticated_client_inactive_and_unverified_requests_do_not_track_activity(): void
    {
        $staff = $this->staff();
        $this->getJson('/api/staff/auth/me')->assertUnauthorized();
        $client = User::factory()->create();
        $this->withToken($client->createToken('client')->plainTextToken)
            ->getJson('/api/staff/auth/me')->assertUnauthorized();
        $this->assertNull($staff->fresh()->last_active_at);

        foreach ([['status' => 'deactivated'], ['status' => 'pending'], ['email_verified_at' => null]] as $attributes) {
            $blocked = $this->staff($attributes);
            $this->actingAs($blocked, 'staff_sanctum')->getJson('/api/staff/auth/me')->assertForbidden();
            $this->assertNull($blocked->fresh()->last_active_at);
        }
    }

    public function test_team_detail_includes_activity_and_access_keys_even_when_null(): void
    {
        $admin = $this->staff();
        $member = $this->staff();
        $this->actingAs($admin, 'staff_sanctum')->getJson('/api/staff/users/'.$member->id)->assertOk()
            ->assertJsonStructure(['staff' => ['last_login_at', 'last_active_at', 'activated_at', 'created_at', 'office_access', 'service_access']])
            ->assertJsonPath('staff.last_login_at', null)->assertJsonPath('staff.last_active_at', null)
            ->assertJsonPath('staff.activated_at', null)->assertJsonPath('staff.created_at', $member->created_at->toISOString())
            ->assertJsonPath('staff.office_access', null)->assertJsonPath('staff.service_access', null);

        $timestamp = now()->startOfSecond();
        $member->forceFill(['last_login_at' => $timestamp, 'last_active_at' => $timestamp, 'activated_at' => $timestamp])->save();
        $this->getJson('/api/staff/users/'.$member->id)->assertOk()
            ->assertJsonPath('staff.last_login_at', $timestamp->toISOString())
            ->assertJsonPath('staff.last_active_at', $timestamp->toISOString())
            ->assertJsonPath('staff.activated_at', $timestamp->toISOString());
    }
}
