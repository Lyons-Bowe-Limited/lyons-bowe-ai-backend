<?php

namespace App\Console\Commands;

use App\Mail\StaffActivationMail;
use App\Models\StaffAuditLog;
use App\Models\StaffInvitation;
use App\Models\StaffUser;
use App\Support\StaffInvitationToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ResendStaffActivation extends Command
{
    protected $signature = 'staff:resend-activation
        {email : The pending staff account email}
        {--show-activation-url : Display the activation URL in local or testing only}';

    protected $description = 'Invalidate existing invitations and resend activation for a pending staff account';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($this->option('show-activation-url') && ! app()->environment(['local', 'testing'])) {
            $this->error('The activation URL may only be displayed in local or testing environments.');

            return self::FAILURE;
        }

        $staffUser = StaffUser::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $staffUser || $staffUser->status !== StaffUser::STATUS_PENDING) {
            $this->error('The activation email could not be resent for this account.');

            return self::FAILURE;
        }

        $rawToken = StaffInvitationToken::generate();
        $activationUrl = StaffInvitationToken::activationUrl($rawToken);

        try {
            DB::transaction(function () use ($staffUser, $email, $rawToken, $activationUrl): void {
                $lockedUser = StaffUser::query()->lockForUpdate()->find($staffUser->id);

                if (! $lockedUser || $lockedUser->status !== StaffUser::STATUS_PENDING) {
                    throw new \RuntimeException('Staff account is no longer pending.');
                }

                $lockedUser->invitations()
                    ->whereNull('accepted_at')
                    ->whereNull('cancelled_at')
                    ->update(['cancelled_at' => now(), 'updated_at' => now()]);

                $invitation = StaffInvitation::query()->create([
                    'staff_user_id' => $lockedUser->id,
                    'email' => $email,
                    'token_hash' => StaffInvitationToken::hash($rawToken),
                    'expires_at' => now()->addHours(24),
                ]);

                StaffAuditLog::query()->create([
                    'staff_user_id' => $lockedUser->id,
                    'action' => 'staff.activation_resent',
                    'subject_type' => StaffInvitation::class,
                    'subject_id' => $invitation->id,
                    'metadata' => ['email' => $email],
                ]);

                Mail::to($email)->send(new StaffActivationMail($lockedUser, $activationUrl));
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The activation email could not be resent. No invitation changes were kept.');

            return self::FAILURE;
        }

        $this->info('A new activation email has been sent. Previous unused invitations were invalidated.');

        if ($this->option('show-activation-url')) {
            $this->line($activationUrl);
        }

        return self::SUCCESS;
    }
}
