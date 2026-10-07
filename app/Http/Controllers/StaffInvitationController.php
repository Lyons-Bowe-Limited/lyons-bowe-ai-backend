<?php

namespace App\Http\Controllers;

use App\Http\Requests\Staff\AcceptStaffInvitationRequest;
use App\Http\Requests\Staff\ValidateStaffInvitationRequest;
use App\Models\StaffAuditLog;
use App\Models\StaffInvitation;
use App\Models\StaffUser;
use App\Support\StaffInvitationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffInvitationController extends Controller
{
    public function validateInvitation(ValidateStaffInvitationRequest $request): JsonResponse
    {
        $invitation = $this->findInvitation($request->validated('token'));

        if (! $invitation) {
            return $this->invalidResponse();
        }

        if ($response = $this->unusableResponse($invitation)) {
            return $response;
        }

        $staffUser = $invitation->staffUser;

        if (! $staffUser || $staffUser->status !== StaffUser::STATUS_PENDING) {
            return $this->invalidResponse();
        }

        return response()->json([
            'valid' => true,
            'staff' => [
                'first_name' => $staffUser->first_name,
                'last_name' => $staffUser->last_name,
                'email' => $staffUser->email,
            ],
            'expires_at' => $invitation->expires_at->toISOString(),
        ]);
    }

    public function accept(AcceptStaffInvitationRequest $request): JsonResponse
    {
        $tokenHash = StaffInvitationToken::hash($request->validated('token'));

        $result = DB::transaction(function () use ($request, $tokenHash): ?JsonResponse {
            $invitation = StaffInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! hash_equals($invitation->token_hash, $tokenHash)) {
                return $this->invalidResponse();
            }

            if ($response = $this->unusableResponse($invitation)) {
                return $response;
            }

            $staffUser = StaffUser::query()->lockForUpdate()->find($invitation->staff_user_id);

            if (! $staffUser || $staffUser->status !== StaffUser::STATUS_PENDING) {
                return $this->invalidResponse();
            }

            $activatedAt = now();
            $staffUser->forceFill([
                'password' => Hash::make($request->validated('password')),
                'status' => StaffUser::STATUS_ACTIVE,
                'email_verified_at' => $activatedAt,
                'activated_at' => $activatedAt,
                'password_changed_at' => $activatedAt,
            ])->save();

            $invitation->forceFill(['accepted_at' => $activatedAt])->save();

            StaffAuditLog::query()->create([
                'staff_user_id' => $staffUser->id,
                'action' => 'staff.account_activated',
                'subject_type' => StaffUser::class,
                'subject_id' => $staffUser->id,
                'metadata' => ['invitation_id' => $invitation->id],
            ]);

            return null;
        }, 3);

        return $result ?? response()->json([
            'message' => 'Your staff account has been activated.',
        ]);
    }

    private function findInvitation(string $rawToken): ?StaffInvitation
    {
        $tokenHash = StaffInvitationToken::hash($rawToken);
        $invitation = StaffInvitation::query()->with('staffUser')->where('token_hash', $tokenHash)->first();

        if (! $invitation || ! hash_equals($invitation->token_hash, $tokenHash)) {
            return null;
        }

        return $invitation;
    }

    private function unusableResponse(StaffInvitation $invitation): ?JsonResponse
    {
        if ($invitation->cancelled_at) {
            return response()->json(['valid' => false, 'message' => 'This invitation has been cancelled.'], 410);
        }

        if ($invitation->accepted_at) {
            return response()->json(['valid' => false, 'message' => 'This invitation has already been used.'], 410);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json(['valid' => false, 'message' => 'This invitation has expired.'], 410);
        }

        return null;
    }

    private function invalidResponse(): JsonResponse
    {
        return response()->json(['valid' => false, 'message' => 'This invitation is invalid.'], 404);
    }
}
