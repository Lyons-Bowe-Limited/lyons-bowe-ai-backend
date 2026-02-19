<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\UkPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Intervention\Image\Laravel\Facades\Image;
use Intervention\Image\Encoders\JpegEncoder;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse
    {
        // Ensure API requests always expect JSON
        $request->headers->set('Accept', 'application/json');
        
        // Normalize 'contact' to 'contact_number' if present
        if ($request->has('contact') && !$request->has('contact_number')) {
            $request->merge(['contact_number' => $request->contact]);
        }
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'contact_number' => ['required', 'string', 'max:20', new UkPhoneNumber()],
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'email_verified_at' => $request->email_verified_at,
            'contact_number' => $request->contact_number,
            'password' => Hash::make($request->password),
        ]);

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'message' => 'Registration successful. Please verify your email address.',
        ], 201);
    }

    /**
     * Login user and create token
     */
    public function login(Request $request): JsonResponse
    {
        // Ensure API requests always expect JSON
        $request->headers->set('Accept', 'application/json');
        
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout user (Revoke the token)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Upload and update the authenticated user's profile image.
     * Image is cropped/resized to 250x250 and saved as JPEG (quality 85).
     */
    public function uploadProfileImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'], // 10MB max upload
        ]);

        $user = $request->user();
        $file = $request->file('image');

        try {
            $image = Image::read($file->getRealPath());
            $image->cover(250, 250, 'center');

            $encoder = new JpegEncoder(quality: 85);
            $encoded = $image->encode($encoder);

            $filename = 'profile-images/' . $user->id . '_' . now()->timestamp . '.jpg';

            Storage::disk('public')->put($filename, (string) $encoded);

            // Delete previous profile image if it exists
            if ($user->profile_image) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $user->update(['profile_image' => $filename]);

            return response()->json([
                'user' => $user->fresh(),
                'message' => 'Profile image updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process image. Please try another file.',
            ], 422);
        }
    }

    /**
     * Verify user's email
     */
    public function verify(Request $request): View|JsonResponse
    {
        try {
            $user = User::findOrFail($request->route('id'));

            if ($user->hasVerifiedEmail()) {
                // If API request, return JSON; otherwise return view
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Email already verified.',
                    ], 400);
                }
                
                return view('email-verification', [
                    'status' => 'already_verified',
                    'user' => $user,
                ]);
            }

            // Verify the signed URL hash
            if (!hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Invalid verification link.',
                    ], 400);
                }
                
                return view('email-verification', [
                    'status' => 'error',
                    'message' => 'Invalid verification link. The link may have expired or is invalid.',
                ]);
            }

            if ($user->markEmailAsVerified()) {
                event(new \Illuminate\Auth\Events\Verified($user));
            }

            // If API request, return JSON; otherwise return view
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Email verified successfully.',
                ]);
            }

            return view('email-verification', [
                'status' => 'success',
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Verification failed. Please try again.',
                ], 400);
            }

            return view('email-verification', [
                'status' => 'error',
                'message' => 'Verification failed. The link may have expired or is invalid.',
            ]);
        }
    }

    /**
     * Resend email verification notification
     */
    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 400);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent.',
        ]);
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        // Ensure API requests always expect JSON
        $request->headers->set('Accept', 'application/json');
        
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link has been sent to your email address.',
            ]);
        }

        // Return error even if email doesn't exist (security best practice)
        return response()->json([
            'message' => 'If that email address exists in our system, we have sent a password reset link.',
        ]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        // Ensure API requests always expect JSON
        $request->headers->set('Accept', 'application/json');
        
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password has been reset successfully.',
            ]);
        }

        if ($status === Password::INVALID_TOKEN) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
                'errors' => [
                    'token' => ['The password reset token is invalid or has expired.'],
                ],
            ], 400);
        }

        if ($status === Password::INVALID_USER) {
            return response()->json([
                'message' => 'Invalid user.',
                'errors' => [
                    'email' => ['We cannot find a user with that email address.'],
                ],
            ], 400);
        }

        return response()->json([
            'message' => 'Unable to reset password. Please try again.',
            'errors' => [
                'email' => ['Unable to reset password.'],
            ],
        ], 400);
    }
}

