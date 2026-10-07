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

        return $next($request);
    }
}
