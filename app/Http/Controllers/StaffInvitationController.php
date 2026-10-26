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
use App\Mail\StaffActivationMail;
use App\Models\StaffRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Services\StaffAuthorisationService;

class StaffInvitationController extends Controller

{
    /**
     * Invite a new staff member from the internal dashboard.
     *
     * Only authorised staff can access this endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
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

        // SECURITY: Only staff with roles.manage may invite a Super Admin.
        if (
            $validated['role'] === 'super_admin'
            && ! app(StaffAuthorisationService::class)->allows(
                $request->user('staff_sanctum'),
                'roles.manage'
            )
        ) {
            return response()->json([
                'message' => 'You are not authorised to assign the Super Admin role.',
            ], 403);
        }

        // NORMALISATION: Match the existing staff email convention.
        $email = mb_strtolower(trim($validated['email']));

        // VALIDATION: Prevent duplicate staff accounts.
        if (StaffUser::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'A staff account with this email address already exists.',
            ]);
        }

        $rawToken = StaffInvitationToken::generate();
        $activationUrl = StaffInvitationToken::activationUrl($rawToken);

        try {
            $staffUser = DB::transaction(function () use (
                $request,
                $validated,
                $email,
                $rawToken,
                $activationUrl
            ): StaffUser {
                $actor = $request->user('staff_sanctum');

                $role = StaffRole::query()
                    ->where('name', $validated['role'])
                    ->firstOrFail();

                // ACCOUNT: Create a pending staff account without a password.
                $staffUser = StaffUser::query()->create([
                    'first_name' => trim($validated['first_name']),
                    'last_name' => trim($validated['last_name']),
                    'email' => $email,
                    'password' => null,
                    'status' => StaffUser::STATUS_PENDING,
                    'invited_by' => $actor->id,
                ]);

                // ROLE: Assign the selected role before activation.
                $staffUser->roles()->attach($role);

                // INVITATION: Store only the hash, never the raw token.
                $invitation = StaffInvitation::query()->create([
                    'staff_user_id' => $staffUser->id,
                    'email' => $email,
                    'token_hash' => StaffInvitationToken::hash($rawToken),
                    'expires_at' => now()->addHours(24),
                    'invited_by' => $actor->id,
                ]);

                // AUDIT: Record who invited the new staff member.
                StaffAuditLog::query()->create([
                    'staff_user_id' => $actor->id,
                    'action' => 'staff.invitation_created',
                    'subject_type' => StaffInvitation::class,
                    'subject_id' => $invitation->id,
                    'metadata' => [
                        'staff_user_id' => $staffUser->id,
                        'email' => $email,
                        'role' => $role->name,
                    ],
                ]);

                // EMAIL: Reuse the existing activation email.
                // A synchronous transport exception rolls back the database changes.
                Mail::to($email)->send(
                    new StaffActivationMail($staffUser, $activationUrl)
                );

                return $staffUser;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The staff invitation could not be sent. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Staff invitation sent successfully.',
            'staff' => new \App\Http\Resources\StaffUserResource($staffUser->load('roles')),
        ], 201);
    }


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
