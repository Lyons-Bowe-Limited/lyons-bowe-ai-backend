<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffUserResource;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\StaffAuditLog;
use Illuminate\Support\Facades\DB;
use App\Services\StaffSuperAdminGuard;

class StaffManagementController extends Controller
{
    /**
     * List staff accounts with optional search and status filters.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,active,deactivated'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StaffUser::query()->with('roles');

        if (! empty($validated['search'])) {
            $search = $validated['search'];

            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $staff = $query
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => StaffUserResource::collection($staff->items()),
            'pagination' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'per_page' => $staff->perPage(),
                'total' => $staff->total(),
            ],
        ]);
    }

    /**
     * Retrieve a single staff account.
     */
    public function show(StaffUser $staffUser): JsonResponse
    {
        $staffUser->load('roles');

        return response()->json([
            'staff' => new StaffUserResource($staffUser),
        ]);
    }


    /**
     * Deactivate a staff account and revoke its access tokens.
     */
    public function deactivate(
        Request $request,
        StaffUser $staffUser,
        StaffSuperAdminGuard $guard
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $staffUser, $validated, $guard): void {
            $actor = $request->user('staff_sanctum');

            // SECURITY: Prevent an administrator from deactivating themselves.
            abort_if(
                $actor->is($staffUser),
                403,
                'You cannot deactivate your own account.'
            );

            // NEW SECURITY: Acquire the shared Super Admin lock.
            // This serialises deactivation and role changes that use the same guard.
            $guard->lock();

            // SECURITY: Lock the target account before changing its status.
            // Always acquire this lock AFTER the shared Super Admin lock.
            $staffUser = StaffUser::query()
                ->lockForUpdate()
                ->findOrFail($staffUser->id);

            // SECURITY: Only active accounts can be deactivated.
            abort_if(
                $staffUser->status !== StaffUser::STATUS_ACTIVE,
                422,
                'Only active staff accounts can be deactivated.'
            );

            // NEW SECURITY: Protect the last active Super Admin.
            // This replaces the previous manual count check.
            // The check happens before any account changes.
            $guard->ensureAnotherActiveAdminExists($staffUser);

            // ACCOUNT: Deactivate the staff member.
            $staffUser->update([
                'status' => StaffUser::STATUS_DEACTIVATED,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->id,
                'deactivation_reason' => $validated['reason'],
            ]);

            // SECURITY: Revoke all access tokens immediately.
            $staffUser->tokens()->delete();

            // AUDIT: Record who deactivated the account and why.
            StaffAuditLog::create([
                'staff_user_id' => $actor->id,
                'action' => 'staff.account_deactivated',
                'subject_type' => StaffUser::class,
                'subject_id' => $staffUser->id,
                'metadata' => [
                    'reason' => $validated['reason'],
                ],
            ]);
        }, 3);

        return response()->json([
            'message' => 'Staff account deactivated successfully.',
        ]);
    }




    /**
     * Reactivate a previously deactivated staff account.
     */
    public function reactivate(Request $request, StaffUser $staffUser): JsonResponse
    {
        DB::transaction(function () use ($request, $staffUser): void {
            $actor = $request->user('staff_sanctum');

            // SECURITY: Lock the target staff account during reactivation.
            $staffUser = StaffUser::query()
                ->lockForUpdate()
                ->findOrFail($staffUser->id);

            // SECURITY: Only previously deactivated accounts can be reactivated.
            abort_if(
                $staffUser->status !== StaffUser::STATUS_DEACTIVATED,
                422,
                'Only deactivated staff accounts can be reactivated.'
            );

            // SECURITY: Require an existing verified account.
            abort_if(
                $staffUser->email_verified_at === null,
                422,
                'Unverified staff accounts cannot be reactivated.'
            );

            $staffUser->update([
                'status' => StaffUser::STATUS_ACTIVE,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]);

            // SECURITY: Revoke any remaining tokens as a precaution.
            // The staff member must log in again after reactivation.
            $staffUser->tokens()->delete();

            // AUDIT: Record who reactivated the account.
            StaffAuditLog::create([
                'staff_user_id' => $actor->id,
                'action' => 'staff.account_reactivated',
                'subject_type' => StaffUser::class,
                'subject_id' => $staffUser->id,
                'metadata' => [
                    'previous_status' => StaffUser::STATUS_DEACTIVATED,
                    'new_status' => StaffUser::STATUS_ACTIVE,
                ],
            ]);
        }, 3);

        return response()->json([
            'message' => 'Staff account reactivated successfully.',
        ]);
    }

}
