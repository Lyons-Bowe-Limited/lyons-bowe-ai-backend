<?php

namespace Tests\Feature;

use App\Mail\StaffActivationMail;
use App\Models\StaffAuditLog;
use App\Models\StaffInvitation;
use App\Models\StaffUser;
use App\Support\StaffInvitationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class StaffInvitationActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_invitation_can_be_validated_without_exposing_internal_data(): void
    {
        [$staffUser, $invitation, $rawToken] = $this->pendingInvitation();

        $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])
            ->assertOk()
            ->assertExactJson([
                'valid' => true,
                'staff' => [
                    'first_name' => $staffUser->first_name,
                    'last_name' => $staffUser->last_name,
                    'email' => $staffUser->email,
                ],
                'expires_at' => $invitation->expires_at->toISOString(),
            ]);

        $this->assertSame(StaffInvitationToken::hash($rawToken), $invitation->getRawOriginal('token_hash'));
        $this->assertNotSame($rawToken, $invitation->getRawOriginal('token_hash'));
    }

    public function test_incorrect_token_is_rejected(): void
    {
        $this->pendingInvitation();

        $this->postJson('/api/staff/invitations/validate', ['token' => str_repeat('x', 64)])
            ->assertNotFound()
            ->assertExactJson(['valid' => false, 'message' => 'This invitation is invalid.']);
    }

    public function test_expired_invitation_is_rejected(): void
    {
        [, $invitation, $rawToken] = $this->pendingInvitation();
        $invitation->update(['expires_at' => now()->subSecond()]);

        $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])
            ->assertGone()
            ->assertExactJson(['valid' => false, 'message' => 'This invitation has expired.']);
    }

    public function test_cancelled_invitation_is_rejected(): void
    {
        [, $invitation, $rawToken] = $this->pendingInvitation();
        $invitation->update(['cancelled_at' => now()]);

        $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])
            ->assertGone()
            ->assertExactJson(['valid' => false, 'message' => 'This invitation has been cancelled.']);
    }

    public function test_accepted_invitation_is_rejected(): void
    {
        [, $invitation, $rawToken] = $this->pendingInvitation();
        $invitation->update(['accepted_at' => now()]);

        $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])
            ->assertGone()
            ->assertExactJson(['valid' => false, 'message' => 'This invitation has already been used.']);
    }

    public function test_password_confirmation_is_required(): void
    {
        [, , $rawToken] = $this->pendingInvitation();

        $this->postJson('/api/staff/invitations/accept', [
            'token' => $rawToken,
            'password' => 'StrongPassword!42',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_password_policy_is_enforced(): void
    {
        foreach (['Short!1A', 'lowercaseonly!1', 'UPPERCASEONLY!1', 'NoNumbersHere!', 'NoSpecialHere12'] as $password) {
            [, , $rawToken] = $this->pendingInvitation(uniqid('person', true).'@example.com');

            $this->postJson('/api/staff/invitations/accept', [
                'token' => $rawToken,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_valid_activation_updates_all_records_and_prevents_reuse(): void
    {
        [$staffUser, $invitation, $rawToken] = $this->pendingInvitation();
        $password = 'StrongPassword!42';

        $this->postJson('/api/staff/invitations/accept', [
            'token' => $rawToken,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk()->assertExactJson([
            'message' => 'Your staff account has been activated.',
        ]);

        $staffUser->refresh();
        $invitation->refresh();

        $this->assertSame(StaffUser::STATUS_ACTIVE, $staffUser->status);
        $this->assertTrue(Hash::check($password, $staffUser->password));
        $this->assertNotSame($password, $staffUser->password);
        $this->assertNotNull($staffUser->email_verified_at);
        $this->assertNotNull($staffUser->activated_at);
        $this->assertNotNull($staffUser->password_changed_at);
        $this->assertNotNull($invitation->accepted_at);

        $audit = StaffAuditLog::query()->where('action', 'staff.account_activated')->firstOrFail();
        $this->assertSame(['invitation_id' => $invitation->id], $audit->metadata);
        $this->assertStringNotContainsString($rawToken, json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));

        $this->postJson('/api/staff/invitations/accept', [
            'token' => $rawToken,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertGone()->assertExactJson([
            'valid' => false,
            'message' => 'This invitation has already been used.',
        ]);
    }

    public function test_failed_activation_rolls_back_every_change(): void
    {
        [$staffUser, $invitation, $rawToken] = $this->pendingInvitation();
        StaffAuditLog::creating(fn () => throw new RuntimeException('Audit storage failed'));

        $this->postJson('/api/staff/invitations/accept', [
            'token' => $rawToken,
            'password' => 'StrongPassword!42',
            'password_confirmation' => 'StrongPassword!42',
        ])->assertServerError();

        $this->assertSame(StaffUser::STATUS_PENDING, $staffUser->fresh()->status);
        $this->assertNull($staffUser->fresh()->password);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseCount('staff_audit_logs', 0);
    }

    public function test_validation_endpoint_is_rate_limited(): void
    {
        [, , $rawToken] = $this->pendingInvitation();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])->assertOk();
        }

        $this->postJson('/api/staff/invitations/validate', ['token' => $rawToken])->assertTooManyRequests();
    }

    public function test_acceptance_endpoint_is_rate_limited(): void
    {
        $payload = [
            'token' => str_repeat('z', 64),
            'password' => 'StrongPassword!42',
            'password_confirmation' => 'StrongPassword!42',
        ];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/staff/invitations/accept', $payload)->assertNotFound();
        }

        $this->postJson('/api/staff/invitations/accept', $payload)->assertTooManyRequests();
    }

    public function test_resend_command_invalidates_old_invitation_and_sends_new_email(): void
    {
        Mail::fake();
        [$staffUser, $oldInvitation, $oldRawToken] = $this->pendingInvitation('pending@example.com');

        $this->artisan('staff:resend-activation', ['email' => ' PENDING@EXAMPLE.COM '])
            ->assertSuccessful();

        $this->assertNotNull($oldInvitation->fresh()->cancelled_at);
        $this->assertSame(2, $staffUser->invitations()->count());

        $newInvitation = $staffUser->invitations()->latest('id')->firstOrFail();
        $this->assertNull($newInvitation->cancelled_at);
        $this->assertNotSame($oldInvitation->token_hash, $newInvitation->token_hash);

        Mail::assertSent(StaffActivationMail::class, function (StaffActivationMail $mail) use ($newInvitation, $oldRawToken): bool {
            parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
            $newRawToken = $query['token'] ?? '';

            $this->assertStringContainsString('?token=', $mail->activationUrl);
            $this->assertStringNotContainsString('account=', $mail->activationUrl);
            $this->assertNotSame($oldRawToken, $newRawToken);
            $this->assertSame(StaffInvitationToken::hash($newRawToken), $newInvitation->getRawOriginal('token_hash'));

            return $mail->hasTo('pending@example.com');
        });

        $audit = StaffAuditLog::query()->where('action', 'staff.activation_resent')->firstOrFail();
        $this->assertStringNotContainsString($oldRawToken, json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_resend_command_refuses_active_accounts(): void
    {
        [$staffUser] = $this->pendingInvitation('active@example.com');
        $staffUser->update(['status' => StaffUser::STATUS_ACTIVE]);

        $this->artisan('staff:resend-activation', ['email' => 'active@example.com'])
            ->expectsOutput('The activation email could not be resent for this account.')
            ->assertFailed();

        $this->assertSame(1, $staffUser->invitations()->count());
    }

    /** @return array{StaffUser, StaffInvitation, string} */
    private function pendingInvitation(string $email = 'leigh@example.com'): array
    {
        $staffUser = StaffUser::query()->create([
            'first_name' => 'Leigh',
            'last_name' => 'Smith',
            'email' => $email,
            'status' => StaffUser::STATUS_PENDING,
        ]);
        $rawToken = StaffInvitationToken::generate();
        $invitation = StaffInvitation::query()->create([
            'staff_user_id' => $staffUser->id,
            'email' => $staffUser->email,
            'token_hash' => StaffInvitationToken::hash($rawToken),
            'expires_at' => now()->addDay(),
        ]);

        return [$staffUser, $invitation, $rawToken];
    }
}
