<?php

use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\AiPlaygroundController;
use App\Http\Controllers\Api\V1\EnquiryAnswerController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StaffAuthController;
use App\Http\Controllers\StaffInvitationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StaffManagementController;

/*
|--------------------------------------------------------------------------
| Public authentication routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::prefix('staff/invitations')
    ->middleware('throttle:staff-invitations')
    ->group(function () {
        Route::post('/validate', [StaffInvitationController::class, 'validateInvitation']);
        Route::post('/accept', [StaffInvitationController::class, 'accept']);
    });

Route::post('/staff/auth/login', [StaffAuthController::class, 'login'])
    ->middleware('throttle:staff-login');

Route::prefix('staff/auth')
    ->middleware(['auth:staff_sanctum', 'staff.active'])
    ->group(function () {
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::post('/logout', [StaffAuthController::class, 'logout']);
    });

/*
|--------------------------------------------------------------------------
| Password reset routes
|--------------------------------------------------------------------------
*/

Route::post(
    '/forgot-password',
    [AuthController::class, 'forgotPassword']
);

Route::post(
    '/reset-password',
    [AuthController::class, 'resetPassword']
);

/*
|--------------------------------------------------------------------------
| Email verification routes
|--------------------------------------------------------------------------
*/

Route::get(
    '/email/verify/{id}/{hash}',
    [AuthController::class, 'verify']
)->name('verification.verify');

Route::post(
    '/email/verification-notification',
    [AuthController::class, 'resendVerification']
)
    ->middleware('auth:sanctum')
    ->name('verification.send');


/*
|--------------------------------------------------------------------------
| Staff routes
|--------------------------------------------------------------------------
*/

Route::prefix('staff')
    ->middleware(['auth:staff_sanctum', 'staff.active'])
    ->group(function () {

        Route::get('/roles', [StaffRoleController::class, 'index'])
            ->middleware('staff.permission:roles.manage');

        Route::put('/users/{staffUser}/role', [StaffRoleController::class, 'update'])
            ->middleware('staff.permission:roles.manage');

        // Staff management — listing and viewing staff accounts.
        Route::get('/users', [StaffManagementController::class, 'index'])
            ->middleware('staff.permission:team.view');

        Route::get('/users/{staffUser}', [StaffManagementController::class, 'show'])
            ->middleware('staff.permission:team.view');

        // Only staff with team.manage can deactivate accounts.
        Route::patch('/users/{staffUser}/deactivate', [StaffManagementController::class, 'deactivate'])
        ->middleware('staff.permission:team.manage');

        // Super Admin permission required to reactivate staff accounts.
        Route::patch('/users/{staffUser}/reactivate', [StaffManagementController::class, 'reactivate'])
            ->middleware('staff.permission:team.manage');

        // Only staff with team.manage permission can invite staff members.
        Route::post('/invitations', [StaffInvitationController::class, 'store'])
            ->middleware('staff.permission:team.manage');
        });

/*
|--------------------------------------------------------------------------
| Protected routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authentication and user routes
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'user']);

    Route::put(
        '/user/profile',
        [AuthController::class, 'updateProfile']
    );

    Route::put(
        '/user/password',
        [AuthController::class, 'updatePassword']
    );

    Route::post(
        '/user/profile-image',
        [AuthController::class, 'uploadProfileImage']
    );

    /*
    |--------------------------------------------------------------------------
    | AI conversation routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/ai/conversations',
        [AiConversationController::class, 'index']
    );

    Route::get(
        '/ai/conversations/starred',
        [AiConversationController::class, 'starred']
    );

    Route::patch(
        '/ai/conversations/{uuid}/star',
        [AiConversationController::class, 'updateStar']
    );

    Route::get(
        '/ai/conversations/{uuid}',
        [AiConversationController::class, 'show']
    );

    /*
    |--------------------------------------------------------------------------
    | AI playground routes
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/ai/playground/chat',
        [AiPlaygroundController::class, 'chat']
    );

    /*
    |--------------------------------------------------------------------------
    | Enquiry workflow routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('v1')->group(function () {
        Route::post(
            '/enquiries',
            [EnquiryController::class, 'store']
        );

        Route::get(
            '/enquiries/{enquiry}',
            [EnquiryController::class, 'show']
        );

        Route::post(
            '/enquiries/{enquiry}/answers',
            [EnquiryAnswerController::class, 'store']
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication test route
    |--------------------------------------------------------------------------
    */

    Route::get('/test', function () {
        return response()->json([
            'message' => 'Authenticated successfully',
        ]);
    });

});
