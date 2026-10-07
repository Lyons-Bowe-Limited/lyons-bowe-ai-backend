<?php

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_verified_staff_can_log_in_case_insensitively(): void
    {
        $staffUser = $this->activeStaff('super_admin');

        $response = $this->postJson('/api/staff/auth/login', [
            'email' => ' ADMIN@EXAMPLE.COM ',
            'password' => 'StrongPassword!42',
        ])->assertOk()
            ->assertJsonPath('staff.id', $staffUser->id)
            ->assertJsonPath('staff.first_name', 'Leigh')
            ->assertJsonPath('staff.last_name', 'Smith')
            ->assertJsonPath('staff.full_name', 'Leigh Smith')
            ->assertJsonPath('staff.email', 'admin@example.com')
            ->assertJsonPath('staff.role', 'super_admin')
            ->assertJsonPath('staff.status', StaffUser::STATUS_ACTIVE)
            ->assertJsonStructure(['staff' => ['permissions'], 'access_token', 'token_type']);

        $this->assertEqualsCanonicalizing(
            config('staff_permissions.roles.super_admin'),
            $response->json('staff.permissions'),
        );
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertNotNull($staffUser->fresh()->last_login_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertArrayNotHasKey('password', $response->json('staff'));
    }

    public function test_incorrect_credentials_use_one_generic_response(): void
    {
        $this->activeStaff();
        $expected = [
            'message' => 'The provided credentials are incorrect.',
            'errors' => ['email' => ['The provided credentials are incorrect.']],
        ];

        $this->postJson('/api/staff/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'IncorrectPassword!42',
        ])->assertUnprocessable()->assertExactJson($expected);

        $this->postJson('/api/staff/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'IncorrectPassword!42',
        ])->assertUnprocessable()->assertExactJson($expected);
    }

    public function test_pending_account_cannot_log_in(): void
    {
        $this->staffUser(['status' => StaffUser::STATUS_PENDING, 'email_verified_at' => null]);

        $this->assertGenericLoginFailure();
    }

    public function test_deactivated_account_cannot_log_in(): void
    {
        $this->staffUser(['status' => StaffUser::STATUS_DEACTIVATED]);

        $this->assertGenericLoginFailure();
    }

    public function test_unverified_account_cannot_log_in(): void
    {
        $this->staffUser(['email_verified_at' => null]);

        $this->assertGenericLoginFailure();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/staff/auth/me')->assertUnauthorized();
    }

    public function test_login_token_authenticates_me_and_returns_the_super_admin_contract(): void
    {
        $staffUser = $this->activeStaff('super_admin');
        $login = $this->postJson('/api/staff/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'StrongPassword!42',
        ])->assertOk();

        $response = $this->withToken($login->json('access_token'))
            ->getJson('/api/staff/auth/me')
            ->assertOk();

        $response->assertExactJson([
            'staff' => [
                'id' => $staffUser->id,
                'first_name' => 'Leigh',
                'last_name' => 'Smith',
                'full_name' => 'Leigh Smith',
                'email' => 'admin@example.com',
                'role' => 'super_admin',
                'status' => StaffUser::STATUS_ACTIVE,
                'permissions' => collect(config('staff_permissions.roles.super_admin'))->sort()->values()->all(),
            ],
        ]);
    }

    public function test_me_rejects_an_invalid_token(): void
    {
        $this->withToken('invalid-token')->getJson('/api/staff/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_current_token(): void
    {
        $staffUser = $this->activeStaff();
        $plainTextToken = $staffUser->createToken('staff-dashboard')->plainTextToken;

        $this->withToken($plainTextToken)
            ->postJson('/api/staff/auth/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Successfully logged out.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($plainTextToken)->getJson('/api/staff/auth/me')->assertUnauthorized();
    }

    public function test_staff_without_required_permission_is_denied(): void
    {
        $this->registerPermissionProtectedRoute();
        $staffUser = $this->staffUser();
        Sanctum::actingAs($staffUser, ['*'], 'staff_sanctum');

        $this->getJson('/api/testing/staff/settings')
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have permission to perform this action.']);
    }

    public function test_super_admin_has_access_to_permission_protected_route(): void
    {
        $this->registerPermissionProtectedRoute();
        $staffUser = $this->activeStaff('super_admin');
        Sanctum::actingAs($staffUser, ['*'], 'staff_sanctum');

        $this->getJson('/api/testing/staff/settings')->assertOk();
    }

    public function test_deactivated_staff_is_rejected_and_its_presented_token_is_revoked(): void
    {
        $staffUser = $this->activeStaff();
        $plainTextToken = $staffUser->createToken('staff-dashboard')->plainTextToken;
        $staffUser->update(['status' => StaffUser::STATUS_DEACTIVATED]);

        $this->withToken($plainTextToken)
            ->getJson('/api/staff/auth/me')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Staff account is not active.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = ['email' => 'unknown@example.com', 'password' => 'IncorrectPassword!42'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/staff/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/staff/auth/login', $payload)->assertTooManyRequests();
    }

    public function test_there_is_no_public_staff_registration_route(): void
    {
        $this->postJson('/api/staff/auth/register', [])->assertNotFound();
    }

    private function activeStaff(string $role = 'basic'): StaffUser
    {
        $staffUser = $this->staffUser();
        $staffUser->roles()->attach(StaffRole::query()->where('name', $role)->firstOrFail());

        return $staffUser;
    }

    private function staffUser(array $attributes = []): StaffUser
    {
        return StaffUser::query()->create(array_merge([
            'first_name' => 'Leigh',
            'last_name' => 'Smith',
            'email' => 'admin@example.com',
            'password' => 'StrongPassword!42',
            'status' => StaffUser::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ], $attributes));
    }

    private function assertGenericLoginFailure(): void
    {
        $this->postJson('/api/staff/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'StrongPassword!42',
        ])->assertUnprocessable()->assertExactJson([
            'message' => 'The provided credentials are incorrect.',
            'errors' => ['email' => ['The provided credentials are incorrect.']],
        ]);
    }

    private function registerPermissionProtectedRoute(): void
    {
        Route::middleware(['auth:staff_sanctum', 'staff.active', 'staff.permission:settings.manage'])
            ->get('/api/testing/staff/settings', fn () => response()->json(['allowed' => true]));
    }
}
