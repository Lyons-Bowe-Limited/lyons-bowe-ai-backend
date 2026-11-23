<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $staffUser = $request->user();

        if (! $staffUser instanceof StaffUser) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if ($staffUser->status !== StaffUser::STATUS_ACTIVE
            || $staffUser->email_verified_at === null) {
            $staffUser->currentAccessToken()?->delete();

            return new JsonResponse(['message' => 'Staff account is not active.'], 403);
        }

        $activeAt = now();
        $cutoff = $activeAt->copy()->subMinutes(5);

        if ($staffUser->last_active_at === null || $staffUser->last_active_at->lt($cutoff)) {
            // Recheck in SQL to prevent concurrent requests from repeating the write.
            // Use the base query so activity does not change updated_at.
            $updated = StaffUser::query()->whereKey($staffUser->id)
                ->where('status', StaffUser::STATUS_ACTIVE)->whereNotNull('email_verified_at')
                ->where(fn ($query) => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $cutoff))
                ->toBase()->update(['last_active_at' => $activeAt]);

            if ($updated) {
                $staffUser->setAttribute('last_active_at', $activeAt);
            }
        }

        return $next($request);
    }
}
