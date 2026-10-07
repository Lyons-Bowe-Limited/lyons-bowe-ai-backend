<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use App\Services\StaffAuthorisationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffPermission
{
    public function __construct(private StaffAuthorisationService $authorisation) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $staffUser = $request->user();

        if (! $staffUser instanceof StaffUser || ! $this->authorisation->allows($staffUser, $permission)) {
            return new JsonResponse(['message' => 'You do not have permission to perform this action.'], 403);
        }

        return $next($request);
    }
}
