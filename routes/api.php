<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPasswordController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);
Route::post('check-reset-token', [PasswordResetController::class, 'checkResetToken']);
Route::post('/check-invitation-token', [InvitationController::class, 'checkInvitationToken']);
Route::post('/set-password', [InvitationController::class, 'setPassword']);

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::prefix('users')->group(function () {
        Route::get('/search', [UserController::class, 'searchStudents']);
        Route::get('/', [UserController::class, 'getAllUsersWithRoles']);
        Route::post('/', [UserController::class, 'store'])->name('users.store');
        Route::get('/by-context', [UserController::class, 'getUsersByContextAndRole']);
        Route::get('/school/{school}', [UserController::class, 'getSchoolUsers']);
        Route::get('/classroom/{classroom}', [UserController::class, 'getClassroomUsers']);

        Route::get('/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('users.delete');
        Route::get('/{user}/roles', [UserController::class, 'getUserRoles']);
        Route::put('/{user}/info', [UserController::class, 'updateUserInfo']);
    });

    // Routes pour le personnel
    Route::post('/users/create-staff', [StaffController::class, 'createStaffUser']);
    Route::post('/users/remove-role', [StaffController::class, 'removeUserRole']);
    Route::post('/users/change-password', [UserPasswordController::class, 'changePassword']);

    Route::prefix('families')->group(function () {
        Route::get('/', [FamilyController::class, 'index']);
        Route::post('/', [FamilyController::class, 'store']);
        Route::get('/{family}', [FamilyController::class, 'show']);
        Route::post('/{family}/comments', [FamilyController::class, 'addComment']);
        Route::post('/{family}/students', [FamilyController::class, 'addStudents']);
        Route::post('/{family}/responsibles', [FamilyController::class, 'addResponsible']);
    });

    Route::prefix('schools')->group(function () {
        Route::get('/', [SchoolController::class, 'index']);
        Route::post('/', [SchoolController::class, 'store']);
        Route::get('/{school}', [SchoolController::class, 'show']);
        Route::put('/{school}', [SchoolController::class, 'update']);
        Route::get('/{school}/families', [SchoolController::class, 'getAllFamiliesInSchool']);
    });
});
