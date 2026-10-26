<?php

namespace App\Http\Controllers;

use App\Models\StaffRole;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\StaffSuperAdminGuard;

class StaffRoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => StaffRole::query()
                ->whereNotIn('name', ['team_member', 'basic'])
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        StaffUser $staffUser,
        StaffSuperAdminGuard $guard
    ): JsonResponse {
        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                Rule::in([
                    'super_admin',
                    'enquiry_team_manager',
                    'enquiry_team_member',
                    'solicitor',
                    'reporting_only',
                ]),
            ],
        ]);

        $role = StaffRole::where('name', $validated['role'])->firstOrFail();

        DB::transaction(function () use ($request, $staffUser, $role, $guard): void {
            $actor = $request->user('staff_sanctum');

            // SECURITY: Staff cannot change their own role.
            abort_if(
                $actor->is($staffUser),
                403,
                'You cannot change your own role.'
            );

            // NEW SECURITY: Acquire the shared admin lock before reading roles.
            $guard->lock();

            // NEW SECURITY: Reload the target after acquiring the lock.
            $staffUser = StaffUser::query()
                ->lockForUpdate()
                ->findOrFail($staffUser->id);

            $previousRoles = $staffUser->roles()->pluck('name')->all();

            // NEW SECURITY: Protect the final active Super Admin BEFORE changing roles.
            if ($role->name !== 'super_admin') {
                $guard->ensureAnotherActiveAdminExists($staffUser);
            }

            $staffUser->roles()->sync([$role->id]);

            // AUDIT: Record the previous and new roles.
            \App\Models\StaffAuditLog::create([
                'staff_user_id' => $actor->id,
                'action' => 'staff.role_updated',
                'subject_type' => StaffUser::class,
                'subject_id' => $staffUser->id,
                'metadata' => [
                    'previous_roles' => $previousRoles,
                    'new_role' => $role->name,
                ],
            ]);
        }, 3);

        return response()->json([
            'message' => 'Staff role updated successfully.',
            'staff' => new \App\Http\Resources\StaffUserResource(
                $staffUser->refresh()
            ),
        ]);
    }
}
