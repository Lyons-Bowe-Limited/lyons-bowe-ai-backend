<?php

namespace Tests\Feature;

use App\Mail\StaffActivationMail;
use App\Models\StaffInvitation;
use App\Models\StaffRole;
use App\Models\StaffUser;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class BootstrapStaffSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('staff:bootstrap-super-admin', Artisan::all());
    }

    public function test_it_bootstraps_the_first_pending_super_admin(): void
    {
        Mail::fake();
        config(['staff.frontend_url' => 'https://team.example.test']);

        $this->artisan('staff:bootstrap-super-admin', [
            '--first-name' => ' Leigh ',
            '--last-name' => ' Smith ',
            '--email' => ' LEIGH@EXAMPLE.COM ',
        ])->assertSuccessful();

        $staffUser = StaffUser::query()->firstOrFail();
        $this->assertSame('Leigh', $staffUser->first_name);
        $this->assertSame('Smith', $staffUser->last_name);
        $this->assertSame('leigh@example.com', $staffUser->email);
        $this->assertNull($staffUser->password);
        $this->assertSame(StaffUser::STATUS_PENDING, $staffUser->status);
        $this->assertTrue($staffUser->roles()->where('name', 'super_admin')->exists());

        $permissionNames = $staffUser->roles()->where('name', 'super_admin')
            ->firstOrFail()->permissions()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(config('staff_permissions.roles.super_admin'), $permissionNames);

        $invitation = StaffInvitation::query()->firstOrFail();
        $this->assertSame($staffUser->id, $invitation->staff_user_id);
        $this->assertSame('leigh@example.com', $invitation->email);
        $this->assertTrue($invitation->expires_at->between(now()->addHours(23)->addMinutes(59), now()->addHours(24)->addMinute()));

        Mail::assertSent(StaffActivationMail::class, function (StaffActivationMail $mail) use ($invitation): bool {
            parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
            $rawToken = $query['token'] ?? '';

            $this->assertStringStartsWith('https://team.example.test/activate-account?', $mail->activationUrl);
            $this->assertStringContainsString('?token=', $mail->activationUrl);
            $this->assertStringNotContainsString('account=', $mail->activationUrl);
            $this->assertNotSame('', $rawToken);
            $this->assertSame(hash('sha256', $rawToken), $invitation->getRawOriginal('token_hash'));
            $this->assertStringNotContainsString($rawToken, json_encode($invitation->getAttributes(), JSON_THROW_ON_ERROR));

            return $mail->hasTo('leigh@example.com');
        });

        $this->assertDatabaseHas('staff_audit_logs', [
            'staff_user_id' => $staffUser->id,
            'action' => 'staff.super_admin_bootstrapped',
        ]);
    }

    public function test_duplicate_staff_email_is_refused(): void
    {
        StaffUser::query()->create([
            'first_name' => 'Existing',
            'last_name' => 'Person',
            'email' => 'person@example.com',
            'status' => StaffUser::STATUS_PENDING,
        ]);

        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions('PERSON@EXAMPLE.COM'))
            ->expectsOutput('A staff user with this email address already exists.')
            ->assertFailed();

        $this->assertSame(1, StaffUser::query()->count());
    }

    public function test_second_pending_or_active_super_admin_is_refused(): void
    {
        Mail::fake();
        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions('first@example.com'))->assertSuccessful();

        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions('second@example.com'))
            ->expectsOutput('A pending or active staff Super Admin already exists.')
            ->assertFailed();

        $this->assertSame(1, StaffUser::query()->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions('not-an-email'))
            ->assertFailed();

        $this->assertDatabaseCount('staff_users', 0);
    }

    public function test_production_requires_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions())
            ->expectsConfirmation('Create the first staff Super Admin in production?', 'no')
            ->expectsOutput('Bootstrap cancelled.')
            ->assertFailed();

        $this->assertDatabaseCount('staff_users', 0);
    }

    public function test_activation_url_cannot_be_displayed_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->artisan('staff:bootstrap-super-admin', [
            ...$this->commandOptions(),
            '--show-activation-url' => true,
        ])->expectsOutput('The activation URL may only be displayed in local or testing environments.')
            ->assertFailed();

        $this->assertDatabaseCount('staff_users', 0);
    }

    public function test_database_work_rolls_back_when_mail_delivery_fails(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Transport failed'));

        $this->artisan('staff:bootstrap-super-admin', $this->commandOptions())
            ->expectsOutput('The Super Admin could not be bootstrapped. No partial account was kept.')
            ->assertFailed();

        $this->assertDatabaseCount('staff_users', 0);
        $this->assertDatabaseCount('staff_invitations', 0);
        $this->assertDatabaseCount('staff_role_user', 0);
        $this->assertDatabaseCount('staff_audit_logs', 0);
    }

    public function test_role_and_permission_seeding_is_idempotent(): void
    {
        $seeder = new StaffRolesAndPermissionsSeeder;
        $seeder->run();
        $seeder->run();

        $roles = config('staff_permissions.roles');
        $permissionCount = collect($roles)->flatten()->unique()->count();

        $this->assertDatabaseCount('staff_roles', count($roles));
        $this->assertDatabaseCount('staff_permissions', $permissionCount);
        $this->assertSame(
            count($roles['super_admin']),
            StaffRole::query()->where('name', 'super_admin')->firstOrFail()->permissions()->count(),
        );
    }

    /** @return array<string, string> */
    private function commandOptions(string $email = 'admin@example.com'): array
    {
        return [
            '--first-name' => 'Admin',
            '--last-name' => 'Person',
            '--email' => $email,
        ];
    }
}
