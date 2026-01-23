<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login-token', [AuthController::class, 'loginToken']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout-token', [AuthController::class, 'logoutToken']);

    // Protected routes that require business context
    Route::middleware('resolve.business')->group(function () {
        // Add your protected API routes here that need business context
        Route::get('/user', function (Request $request) {
            return $request->user();
        });
    });
});
