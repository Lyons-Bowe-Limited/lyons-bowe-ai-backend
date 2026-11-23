<?php

namespace App\Http\Controllers;

use App\Models\StaffInvitation;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaffUserCountsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $accounts = StaffUser::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->whereIn('status', [StaffUser::STATUS_ACTIVE, StaffUser::STATUS_DEACTIVATED])
            ->groupBy('status')->pluck('aggregate', 'status');

        // Only invitations that can still activate a pending account count.
        // Count recipients once, even if multiple outstanding tokens exist.
        $pending = StaffInvitation::query()
            ->whereNull('accepted_at')->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->whereHas('staffUser', fn ($query) => $query->where('status', StaffUser::STATUS_PENDING))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('staff_users')
                    ->whereRaw('LOWER(TRIM(staff_users.email)) = LOWER(TRIM(staff_invitations.email))')
                    ->whereIn('staff_users.status', [StaffUser::STATUS_ACTIVE, StaffUser::STATUS_DEACTIVATED]);
            })
            ->distinct()->count(DB::raw('LOWER(TRIM(staff_invitations.email))'));

        $active = (int) ($accounts[StaffUser::STATUS_ACTIVE] ?? 0);
        $deactivated = (int) ($accounts[StaffUser::STATUS_DEACTIVATED] ?? 0);

        return response()->json([
            'total' => $active + $pending + $deactivated,
            'active' => $active,
            'pending' => $pending,
            'deactivated' => $deactivated,
        ]);
    }
}
