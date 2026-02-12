<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password reset routes
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Email verification routes
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
    ->middleware('auth:sanctum')
    ->name('verification.send');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Add your other protected routes here
    Route::get('/test', function () {
        return response()->json(['message' => 'Authenticated successfully']);
    });
});

