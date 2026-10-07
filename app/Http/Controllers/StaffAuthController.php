<?php

namespace App\Http\Controllers;

use App\Http\Requests\Staff\StaffLoginRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    public function login(StaffLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $staffUser = StaffUser::query()
            ->whereRaw('LOWER(email) = ?', [$credentials['email']])
            ->first();

        if (! $this->canAuthenticate($staffUser, $credentials['password'])) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => ['email' => ['The provided credentials are incorrect.']],
            ], 422);
        }

        $staffUser->forceFill(['last_login_at' => now()])->save();
        $token = $staffUser->createToken('staff-dashboard')->plainTextToken;

        return response()->json([
            'staff' => (new StaffUserResource($staffUser))->resolve($request),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'staff' => (new StaffUserResource($request->user()))->resolve($request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Successfully logged out.']);
    }

    private function canAuthenticate(?StaffUser $staffUser, string $password): bool
    {
        return $staffUser instanceof StaffUser
            && $staffUser->status === StaffUser::STATUS_ACTIVE
            && $staffUser->email_verified_at !== null
            && $staffUser->password !== null
            && Hash::check($password, $staffUser->password);
    }
}
