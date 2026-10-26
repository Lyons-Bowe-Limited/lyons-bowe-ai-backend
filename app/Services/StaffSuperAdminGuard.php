<?php

namespace App\Services;

use App\Models\StaffRole;
use App\Models\StaffUser;

class StaffSuperAdminGuard
{
    /**
     * SECURITY: Must be called inside a database transaction.
     *
     * Lock the shared super_admin role row so concurrent role changes
     * and deactivations cannot independently remove the final admin.
     */
    public function lock(): void
    {
        StaffRole::query()
            ->where('name', 'super_admin')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * SECURITY: Check whether the target is the last active Super Admin.
     *
     * Call lock() first, within the same transaction.
     */
    public function ensureAnotherActiveAdminExists(StaffUser $target): void
    {
        if (
            $target->status !== StaffUser::STATUS_ACTIVE ||
            ! $target->roles()->where('name', 'super_admin')->exists()
        ) {
            return;
        }

        $activeAdminCount = StaffUser::query()
            ->where('status', StaffUser::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) =>
                $query->where('name', 'super_admin')
            )
            ->count();

        abort_if(
            $activeAdminCount <= 1,
            422,
            'The last active Super Admin cannot be reassigned or deactivated.'
        );
    }
}
