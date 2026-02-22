<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ActivityLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-mfa', [AuthController::class, 'verifyMfa']);
    Route::post('/verify-registration', [AuthController::class, 'verifyRegistration']);
    Route::post('/resend-registration-otp', [AuthController::class, 'resendRegistrationOtp']);
    Route::post('/resend-mfa', [AuthController::class, 'resendMfa']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/resend-forgot-otp', [AuthController::class, 'resendForgotOtp']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:api')->group(function (): void {
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/activity-logs/months', [ActivityLogController::class, 'months']);
    Route::delete('/activity-logs/{id}', [ActivityLogController::class, 'destroy']);
    Route::post('/activity-logs/bulk-delete', [ActivityLogController::class, 'bulkDestroy']);
});
