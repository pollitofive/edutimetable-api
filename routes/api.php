<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\ScheduleChangeController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login-token', [AuthController::class, 'loginToken']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateMe']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);
    Route::post('/logout-token', [AuthController::class, 'logoutToken']);

    // Protected routes that require business context
    Route::middleware('resolve.business')->group(function () {
        // Add your protected API routes here that need business context
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Quick search of the header (students, teachers and courses)
        Route::get('/search', [SearchController::class, 'index']);

        // Enrollment REST API
        Route::get('/enrollments/schedules', [EnrollmentController::class, 'schedules']);
        Route::get('/schedules/{scheduleId}/eligible-students', [EnrollmentController::class, 'eligibleStudents']);
        Route::get('/students/{studentId}/compatible-schedules', [EnrollmentController::class, 'compatibleSchedules']);
        Route::get('/students', [EnrollmentController::class, 'students']);
        Route::get('/enrollments', [EnrollmentController::class, 'index']);
        Route::post('/enrollments', [EnrollmentController::class, 'store']);
        Route::patch('/enrollments/{id}', [EnrollmentController::class, 'update']);
        Route::post('/enrollments/{id}/drop', [EnrollmentController::class, 'drop']);
        Route::delete('/enrollments/{id}', [EnrollmentController::class, 'destroy']);

        // Schedule change simulator ("what if" + apply)
        Route::post('/schedule-changes/preview', [ScheduleChangeController::class, 'preview']);
        Route::post('/schedule-changes/apply', [ScheduleChangeController::class, 'apply']);
    });
});
