<?php

use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\AiPlaygroundController;
use App\Http\Controllers\Api\V1\EnquiryAnswerController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public authentication routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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