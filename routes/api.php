<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AppSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilityController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/config', ConfigController::class);
Route::get('/capabilities', CapabilityController::class);

Route::prefix('/auth')->group(function () {
    Route::get('/policy', [AuthController::class, 'policy']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::post('/resend-2fa', [AuthController::class, 'resendTwoFactor']);
    Route::post('/verify-2fa', [AuthController::class, 'verifyTwoFactor']);
});

Route::prefix('/admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::patch('/users/{userId}', [AdminUserController::class, 'update']);
    Route::delete('/users/{userId}', [AdminUserController::class, 'destroy']);

    Route::get('/app-settings', [AppSettingsController::class, 'show']);
    Route::patch('/app-settings', [AppSettingsController::class, 'update']);
});

Route::post('/files/upload', [FileController::class, 'upload']);
Route::get('/files/{artifactId}', [FileController::class, 'show']);
Route::post('/chat', ChatController::class);
