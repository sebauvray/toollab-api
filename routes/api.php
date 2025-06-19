<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CursusController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StudentClassroomController;
use App\Http\Controllers\Api\TarificationController;
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
Route::post('/schools', [SchoolController::class, 'store']);

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

    Route::post('/users/create-staff', [StaffController::class, 'createStaffUser']);
    Route::post('/users/remove-role', [StaffController::class, 'removeUserRole']);
    Route::post('/users/change-password', [UserPasswordController::class, 'changePassword']);

    Route::prefix('families')->group(function () {
        Route::get('/', [FamilyController::class, 'index']);
        Route::post('/', [FamilyController::class, 'store']);
        Route::get('/{family}', [FamilyController::class, 'show']);
        Route::put('/{family}', [FamilyController::class, 'update']);
        Route::post('/{family}/comments', [FamilyController::class, 'addComment']);
        Route::post('/{family}/students', [FamilyController::class, 'addStudents']);
        Route::put('/{family}/students/{student}', [FamilyController::class, 'updateStudent']);
        Route::delete('/{family}/students/{student}', [FamilyController::class, 'deleteStudent']);
        Route::post('/{family}/responsibles', [FamilyController::class, 'addResponsible']);
        Route::post('/{family}/responsible', [FamilyController::class, 'addResponsibleToFamily']);
        Route::put('/{family}/responsible/{responsible}', [FamilyController::class, 'updateResponsible']);
    });



    Route::prefix('cursus')->middleware('checkrole:director,admin')->group(function () {
        Route::get('/', [CursusController::class, 'index']);
        Route::post('/', [CursusController::class, 'store']);
        Route::get('/{cursus}', [CursusController::class, 'show']);
        Route::put('/{cursus}', [CursusController::class, 'update']);
        Route::delete('/{cursus}', [CursusController::class, 'destroy']);
    });

    Route::prefix('classrooms')->group(function () {
        Route::get('/', [ClassroomController::class, 'index']);
        Route::post('/', [ClassroomController::class, 'store']);
        Route::get('/{classroom}', [ClassroomController::class, 'show']);
        Route::put('/{classroom}', [ClassroomController::class, 'update']);
        Route::delete('/{id}', [ClassroomController::class, 'destroy']);
    });

    Route::prefix('schools')->group(function () {
        Route::get('/', [SchoolController::class, 'index']);
        Route::get('/{school}', [SchoolController::class, 'show']);
        Route::put('/{school}', [SchoolController::class, 'update']);
        Route::get('/{school}/families', [SchoolController::class, 'getAllFamiliesInSchool']);
    });

    Route::post('/student-classrooms/enroll', [StudentClassroomController::class, 'enroll']);
    Route::post('/student-classrooms/unenroll', [StudentClassroomController::class, 'unenroll']);
    Route::get('/families/{family}/enrollments', [StudentClassroomController::class, 'getFamilyEnrollments']);


    Route::prefix('tarification')->middleware('checkrole:director,admin')->group(function () {
        Route::get('/cursus', [TarificationController::class, 'index']);
        Route::post('/cursus/{cursus}/tarif', [TarificationController::class, 'updateTarif']);
        Route::post('/cursus/{cursus}/reduction-familiale', [TarificationController::class, 'storeReductionFamiliale']);
        Route::put('/reduction-familiale/{reduction}', [TarificationController::class, 'updateReductionFamiliale']);
        Route::delete('/reduction-familiale/{reduction}', [TarificationController::class, 'deleteReductionFamiliale']);
        Route::post('/cursus/{cursus}/reduction-multi-cursus', [TarificationController::class, 'storeReductionMultiCursus']);
        Route::put('/reduction-multi-cursus/{reduction}', [TarificationController::class, 'updateReductionMultiCursus']);
        Route::delete('/reduction-multi-cursus/{reduction}', [TarificationController::class, 'deleteReductionMultiCursus']);
        Route::post('/calculer', [TarificationController::class, 'calculerTarifs']);
    });

    Route::prefix('families/{family}/paiements')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\PaiementController::class, 'show']);
        Route::post('/lignes', [App\Http\Controllers\Api\PaiementController::class, 'ajouterLigne']);
        Route::put('/lignes/{ligne}', [App\Http\Controllers\Api\PaiementController::class, 'modifierLigne']);
        Route::delete('/lignes/{ligne}', [App\Http\Controllers\Api\PaiementController::class, 'supprimerLigne']);
    });
});
