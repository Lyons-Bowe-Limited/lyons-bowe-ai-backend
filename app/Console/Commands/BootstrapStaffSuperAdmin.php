<?php

namespace App\Console\Commands;

use App\Mail\StaffActivationMail;
use App\Models\StaffAuditLog;
use App\Models\StaffInvitation;
use App\Models\StaffRole;
use App\Models\StaffUser;
use App\Support\StaffInvitationToken;
use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

class BootstrapStaffSuperAdmin extends Command
{
    protected $signature = 'staff:bootstrap-super-admin
        {--first-name= : The staff member\'s first name}
        {--last-name= : The staff member\'s last name}
        {--email= : The staff member\'s work email}
        {--show-activation-url : Display the activation URL in local or testing only}';

    protected $description = 'Create the first pending staff Super Admin and send an activation email';

    public function handle(): int
    {
        $firstName = trim((string) ($this->option('first-name') ?: $this->ask('First name')));
        $lastName = trim((string) ($this->option('last-name') ?: $this->ask('Last name')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Work email'))));

        $validator = Validator::make(compact('firstName', 'lastName', 'email'), [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($this->option('show-activation-url') && ! app()->environment(['local', 'testing'])) {
            $this->error('The activation URL may only be displayed in local or testing environments.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->confirm('Create the first staff Super Admin in production?')) {
            $this->warn('Bootstrap cancelled.');

            return self::FAILURE;
        }

        if (StaffUser::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('A staff user with this email address already exists.');

            return self::FAILURE;
        }

        if ($this->superAdminExists()) {
            $this->error('A pending or active staff Super Admin already exists.');

            return self::FAILURE;
        }

        $rawToken = StaffInvitationToken::generate();
        $activationUrl = StaffInvitationToken::activationUrl($rawToken);

        try {
            DB::transaction(function () use ($firstName, $lastName, $email, $rawToken, $activationUrl): void {
                (new StaffRolesAndPermissionsSeeder)->run();

                // Recheck under the transaction to reduce concurrent bootstrap races.
                if ($this->superAdminExists()) {
                    throw new \RuntimeException('A pending or active staff Super Admin already exists.');
                }

                $staffUser = StaffUser::query()->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => null,
                    'status' => StaffUser::STATUS_PENDING,
                ]);

                $role = StaffRole::query()->where('name', 'super_admin')->firstOrFail();
                $staffUser->roles()->attach($role);

                StaffInvitation::query()->create([
                    'staff_user_id' => $staffUser->id,
                    'email' => $email,
                    'token_hash' => StaffInvitationToken::hash($rawToken),
                    'expires_at' => now()->addHours(24),
                ]);

                StaffAuditLog::query()->create([
                    'staff_user_id' => $staffUser->id,
                    'action' => 'staff.super_admin_bootstrapped',
                    'subject_type' => StaffUser::class,
                    'subject_id' => $staffUser->id,
                    'metadata' => ['email' => $email],
                ]);

                // Synchronous delivery makes a transport exception roll back the bootstrap.
                Mail::to($email)->send(new StaffActivationMail($staffUser, $activationUrl));
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The Super Admin could not be bootstrapped. No partial account was kept.');

            return self::FAILURE;
        }

        $this->info('Pending staff Super Admin created and activation email sent.');

        if ($this->option('show-activation-url')) {
            $this->line($activationUrl);
        }

        return self::SUCCESS;
    }

    private function superAdminExists(): bool
    {
        return StaffUser::query()
            ->whereIn('status', [StaffUser::STATUS_PENDING, StaffUser::STATUS_ACTIVE])
            ->whereHas('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->exists();
    }
}
