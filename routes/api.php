<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CursusController;
use App\Http\Controllers\Api\DirectorHandoverController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\FamilyDeletionController;
use App\Http\Controllers\Api\FamilyImportController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StudentClassroomController;
use App\Http\Controllers\Api\TarificationController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPasswordController;
use App\Http\Controllers\Api\SchoolYearController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Public
Route::middleware('throttle:login')->post('login', [AuthController::class, 'login']);
Route::middleware('throttle:password-reset')->group(function () {
    Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);
});
Route::middleware('throttle:token-check')->group(function () {
    Route::post('check-reset-token', [PasswordResetController::class, 'checkResetToken']);
    Route::post('/check-invitation-token', [InvitationController::class, 'checkInvitationToken']);
    Route::post('/set-password', [InvitationController::class, 'setPassword']);
    Route::post('/director-handover/check', [DirectorHandoverController::class, 'check']);
    Route::post('/director-handover/accept', [DirectorHandoverController::class, 'accept']);
    Route::post('/director-handover/decline', [DirectorHandoverController::class, 'decline']);
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Auth uniquement (sans contexte école)
    Route::get('/me/invitations', [InvitationController::class, 'myInvitations']);
    Route::post('/me/invitations/accept', [InvitationController::class, 'acceptInvitation']);
    Route::post('/me/invitations/decline', [InvitationController::class, 'declineInvitation']);
    Route::post('/users/change-password', [UserPasswordController::class, 'changePassword']);
    Route::get('/users/{user}/roles', [UserController::class, 'getUserRoles'])->whereNumber('user');
    Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');

    Route::get('/schools', [SchoolController::class, 'index']);
    Route::get('/schools/{school}', [SchoolController::class, 'show']);
    Route::middleware('superadmin')->group(function () {
        Route::post('/schools', [SchoolController::class, 'store']);
    });
    Route::middleware(['school', 'permission:school.settings.update'])->put('/schools/{school}', [SchoolController::class, 'update']);

    // Avec contexte école (header X-School-Id requis)
    Route::middleware('school')->group(function () {

        // Endpoints année scolaire : school-scoped mais PAS year-scoped
        // (sinon impossible de lister les années archivées)
        Route::prefix('school-years')->group(function () {
            Route::get('/', [SchoolYearController::class, 'index']);
            Route::middleware('permission:school_years.manage')->group(function () {
                Route::post('/', [SchoolYearController::class, 'store']);
                Route::post('/{schoolYear}/close', [SchoolYearController::class, 'close']);
                Route::post('/{schoolYear}/outcomes-toggle', [SchoolYearController::class, 'toggleOutcomes']);
                Route::get('/{schoolYear}/classrooms', [SchoolYearController::class, 'classroomsForReconduction']);
            });
        });

        Route::middleware('permission:school_years.manage')
            ->post('/classrooms/{classroom}/reconduct', [SchoolYearController::class, 'reconductClassroom']);

        // Lecture/gestion utilisateurs et staff — toujours autorisé même en consultation
        // d'une année archivée (gestion de la plateforme, pas des données pédagogiques)
        Route::prefix('users')->group(function () {
            // search est year-scopé : élèves inscrits dans l'année sélectionnée, plus les
            // élèves jamais affectés à une classe (une famille existe avant toute affectation).
            Route::middleware('schoolyear')->get('/search', [UserController::class, 'searchStudents']);
            Route::get('/', [UserController::class, 'getAllUsersWithRoles']);
            Route::get('/by-context', [UserController::class, 'getUsersByContextAndRole']);
            Route::get('/teachers', [UserController::class, 'listTeachers']);
            Route::get('/school/{school}', [UserController::class, 'getSchoolUsers']);
            Route::get('/classroom/{classroom}', [UserController::class, 'getClassroomUsers']);
        });

        Route::post('/users/create-staff', [StaffController::class, 'createStaffUser']);
        Route::post('/users/add-role', [StaffController::class, 'addUserRole']);
        Route::post('/users/remove-role', [StaffController::class, 'removeUserRole']);
        Route::post('/users/remove-from-school', [StaffController::class, 'removeUserFromSchool']);

        // Passation de direction : gestion de compte, hors année scolaire. Le contrôleur
        // revérifie le rôle director sans le bypass super-admin de checkrole.
        Route::prefix('director-handover')->middleware('checkrole:director')->group(function () {
            Route::get('/', [DirectorHandoverController::class, 'current']);
            Route::middleware('throttle:10,1')->group(function () {
                Route::post('/', [DirectorHandoverController::class, 'store']);
                Route::post('/{id}/resend', [DirectorHandoverController::class, 'resend'])->whereNumber('id');
            });
            Route::post('/{id}/cancel', [DirectorHandoverController::class, 'cancel'])->whereNumber('id');
        });

        Route::prefix('schools')->group(function () {
            Route::get('/{school}/families', [SchoolController::class, 'getAllFamiliesInSchool']);
        });

        // Routes year-scoped : header X-School-Year-Id optionnel (défaut = année active).
        // Le middleware schoolyear rejette toute mutation (POST/PUT/PATCH/DELETE)
        // si l'année courante est archivée (closed_at != null ou is_active=false).
        Route::middleware('schoolyear')->group(function () {
            // Création/modification/suppression utilisateur (élèves notamment) — bloquée sur archive
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.delete');
            Route::put('/users/{user}/info', [UserController::class, 'updateUserInfo'])->whereNumber('user');

            Route::prefix('families')->group(function () {
                Route::middleware('permission:families.import_export')->group(function () {
                    Route::get('/export', [FamilyController::class, 'exportStudents']);
                    Route::get('/import-template', [FamilyImportController::class, 'template']);
                    Route::post('/import', [FamilyImportController::class, 'import']);
                    Route::get('/imports/{familyImport}', [FamilyImportController::class, 'show'])->whereNumber('familyImport');
                });

                Route::middleware('permission:families.delete')->group(function () {
                    // Suppression réversible. /trashed est déclarée avant /{family}
                    // pour que le segment littéral ne soit pas capté par le binding.
                    Route::get('/trashed', [FamilyDeletionController::class, 'trashed']);
                    Route::get('/{family}/deletion-preview', [FamilyDeletionController::class, 'preview'])->whereNumber('family');
                    Route::delete('/{family}', [FamilyDeletionController::class, 'destroy'])->whereNumber('family');
                    Route::post('/{familyId}/restore', [FamilyDeletionController::class, 'restore'])->whereNumber('familyId');
                    Route::post('/{familyId}/purge', [FamilyDeletionController::class, 'purge'])->whereNumber('familyId');
                });
                Route::get('/', [FamilyController::class, 'index']);
                Route::post('/', [FamilyController::class, 'store']);
                Route::get('/{family}', [FamilyController::class, 'show']);
                Route::post('/{family}/comments', [FamilyController::class, 'addComment']);
                Route::post('/{family}/students', [FamilyController::class, 'addStudents']);
                Route::put('/{family}/students/{student}', [FamilyController::class, 'updateStudent']);
                Route::delete('/{family}/students/{student}', [FamilyController::class, 'deleteStudent']);
                Route::post('/{family}/responsibles', [FamilyController::class, 'addResponsible']);
                Route::post('/{family}/responsible', [FamilyController::class, 'addResponsibleToFamily']);
                Route::put('/{family}/responsible/{responsible}', [FamilyController::class, 'updateResponsible']);
                Route::get('/{family}/enrollments', [StudentClassroomController::class, 'getFamilyEnrollments']);
            });

            Route::prefix('cursus')->middleware('permission:cursus.manage')->group(function () {
                Route::get('/', [CursusController::class, 'index']);
                Route::post('/', [CursusController::class, 'store']);
                Route::get('/{cursus}', [CursusController::class, 'show']);
                Route::put('/{cursus}', [CursusController::class, 'update']);
                Route::delete('/{cursus}', [CursusController::class, 'destroy']);
            });

            Route::prefix('classrooms')->group(function () {
                Route::get('/', [ClassroomController::class, 'index']);
                Route::get('/{classroom}', [ClassroomController::class, 'show']);
                Route::middleware('permission:classrooms.manage')->group(function () {
                    Route::post('/', [ClassroomController::class, 'store']);
                    Route::put('/{classroom}', [ClassroomController::class, 'update']);
                    Route::delete('/{id}', [ClassroomController::class, 'destroy']);
                });
            });

            Route::prefix('admin/classrooms')->middleware('permission:classrooms.supervise')->group(function () {
                Route::get('/', [ClassroomController::class, 'getAdminClassrooms']);
                Route::get('/export', [ClassroomController::class, 'exportClassrooms']);
                Route::get('/{classroom}/suivi', [ClassroomController::class, 'adminSuivi']);
                Route::delete('/{classroom}/students/{student}', [ClassroomController::class, 'removeStudentFromClass']);
            });

            Route::middleware('permission:classrooms.supervise')->get('/admin/outcomes', [ClassroomController::class, 'adminOutcomesOverview']);

            Route::middleware('permission:classrooms.supervise')->get('/schedules', [ScheduleController::class, 'index']);

            Route::middleware('schoolyear')->prefix('teacher')->group(function () {
                Route::get('/classrooms', [TeacherController::class, 'myClassrooms']);
                Route::get('/classrooms/{classroom}/students', [TeacherController::class, 'classroomStudents']);
                Route::post('/classrooms/{classroom}/outcomes', [TeacherController::class, 'saveOutcomes']);
                Route::get('/classrooms/{classroom}/attendance', [TeacherController::class, 'classroomAttendance']);
                Route::get('/classrooms/{classroom}/attendance-matrix', [TeacherController::class, 'classroomAttendanceMatrix']);
                Route::post('/classrooms/{classroom}/attendance', [TeacherController::class, 'saveAttendance']);
                Route::get('/schedules', [ScheduleController::class, 'mySchedules']);
            });

            Route::middleware('permission:enrollments.manage')->group(function () {
                Route::post('/student-classrooms/enroll', [StudentClassroomController::class, 'enroll']);
                Route::post('/student-classrooms/unenroll', [StudentClassroomController::class, 'unenroll']);
            });

            Route::prefix('tarification')->middleware('permission:tarification.manage')->group(function () {
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
                Route::get('/facture', [App\Http\Controllers\Api\PaiementController::class, 'facture']);
                Route::middleware('permission:payments.edit')->group(function () {
                    Route::post('/lignes', [App\Http\Controllers\Api\PaiementController::class, 'ajouterLigne']);
                    Route::put('/lignes/{ligne}', [App\Http\Controllers\Api\PaiementController::class, 'modifierLigne']);
                    Route::delete('/lignes/{ligne}', [App\Http\Controllers\Api\PaiementController::class, 'supprimerLigne']);
                });
            });

            Route::prefix('statistics')->middleware('permission:statistics.view')->group(function () {
                Route::get('/overview', [StatisticsController::class, 'overview']);
                Route::get('/unpaid-families', [StatisticsController::class, 'unpaidFamilies']);
                Route::post('/search-payments', [StatisticsController::class, 'searchPayments']);
                Route::get('/enrollment-trends', [StatisticsController::class, 'enrollmentTrends']);
                Route::get('/revenue-by-month', [StatisticsController::class, 'revenueByMonth']);
                Route::get('/payments', [StatisticsController::class, 'payments']);
                Route::get('/export-payments', [StatisticsController::class, 'exportPayments']);
                Route::get('/export-unpaid-families', [StatisticsController::class, 'exportUnpaidFamilies']);
                Route::get('/available-banks', [StatisticsController::class, 'availableBanks']);
            });
        });
    });
});
