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

        // DEBUG: Endpoint to verify business context
        Route::get('/debug/business-context', function (Request $request) {
            $currentBusiness = app(\App\Services\CurrentBusiness::class);

            return response()->json([
                'header_x_business_id' => $request->header('X-Business-Id'),
                'current_business_id' => $currentBusiness->id(),
                'current_business_has_id' => $currentBusiness->hasId(),
                'user_default_business_id' => $request->user()->default_business_id,
                'request_attribute_business_id' => $request->attributes->get('business_id'),
            ]);
        });
    });
});
