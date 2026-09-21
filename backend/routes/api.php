
<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AcademicSupportController;
use App\Http\Controllers\Api\AcademicAssignmentController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamSubjectController;
use App\Http\Controllers\Api\FeeTypeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MarkController;
use App\Http\Controllers\Api\FeeManagementController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RoutineController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentFeeController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', function (Request $request) {
        return response()->json([
            'user' => $request->user()->load('school'),
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Super Admin Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware('super_admin')->group(function () {
        Route::apiResource('schools', SchoolController::class);
        Route::get('/super-admin/dashboard', [DashboardController::class, 'superAdmin']);
    });

    /*
    |--------------------------------------------------------------------------
    | School Access Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware('school_access')->group(function () {

        Route::get('/school/dashboard', [DashboardController::class, 'school'])
            ->middleware('school_admin');

        Route::get('/reports/students', [ReportController::class, 'students']);
        Route::get('/reports/attendance', [ReportController::class, 'attendance']);
        Route::get('/reports/fees', [ReportController::class, 'fees']);
        Route::get('/reports/results', [ReportController::class, 'results']);

        // Attendance Management
        Route::get('/attendance/students', [AttendanceController::class, 'students']);
        Route::post('/attendances/bulk', [AttendanceController::class, 'bulkStore']);
        Route::apiResource('attendances', AttendanceController::class);

        // Marks and Results Management
        Route::get('/marks/students', [MarkController::class, 'students']);
        Route::post('/marks/bulk', [MarkController::class, 'bulkStore']);
        Route::apiResource('marks', MarkController::class);
        Route::get('/results', [MarkController::class, 'results']);
        Route::get('/students/{student}/results/{exam}', [MarkController::class, 'result']);

        // Fees and Payments Management
        Route::apiResource('student-fees', StudentFeeController::class);
        Route::get('/students/{student}/fees/summary', [PaymentController::class, 'summary']);

        // Routine viewing is available to authenticated school users.
        Route::get('/routines', [RoutineController::class, 'index'])->name('routines.index');
        Route::get('/routines/{routine}', [RoutineController::class, 'show'])->name('routines.show');

        /*
        |--------------------------------------------------------------------------
        | School Admin Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware('school_admin')->group(function () {

            // User Management
            Route::apiResource('users', UserController::class);

            // Teacher Management
            Route::apiResource('teachers', TeacherController::class);

            // Student Management
            Route::apiResource('students', StudentController::class);

            // Class Management
            Route::apiResource('classes', SchoolClassController::class);

            Route::get('/versions', [AcademicSupportController::class, 'index'])->defaults('resource', 'version');
            Route::post('/versions', [AcademicSupportController::class, 'store'])->defaults('resource', 'version');
            Route::get('/versions/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'version');
            Route::match(['put', 'patch'], '/versions/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'version');
            Route::delete('/versions/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'version');
            Route::get('/groups', [AcademicSupportController::class, 'index'])->defaults('resource', 'group');
            Route::post('/groups', [AcademicSupportController::class, 'store'])->defaults('resource', 'group');
            Route::get('/groups/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'group');
            Route::match(['put', 'patch'], '/groups/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'group');
            Route::delete('/groups/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'group');
            Route::get('/academic-years', [AcademicSupportController::class, 'index'])->defaults('resource', 'academic-year');
            Route::post('/academic-years', [AcademicSupportController::class, 'store'])->defaults('resource', 'academic-year');
            Route::get('/academic-years/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'academic-year');
            Route::match(['put', 'patch'], '/academic-years/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'academic-year');
            Route::delete('/academic-years/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'academic-year');
            Route::get('/transports', [AcademicSupportController::class, 'index'])->defaults('resource', 'transport');
            Route::post('/transports', [AcademicSupportController::class, 'store'])->defaults('resource', 'transport');
            Route::get('/transports/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'transport');
            Route::match(['put', 'patch'], '/transports/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'transport');
            Route::delete('/transports/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'transport');
            Route::get('/routine-periods', [AcademicSupportController::class, 'index'])->defaults('resource', 'routine-period');
            Route::post('/routine-periods', [AcademicSupportController::class, 'store'])->defaults('resource', 'routine-period');
            Route::get('/routine-periods/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'routine-period');
            Route::match(['put', 'patch'], '/routine-periods/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'routine-period');
            Route::delete('/routine-periods/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'routine-period');
            Route::get('/routine-rooms', [AcademicSupportController::class, 'index'])->defaults('resource', 'routine-room');
            Route::post('/routine-rooms', [AcademicSupportController::class, 'store'])->defaults('resource', 'routine-room');
            Route::get('/routine-rooms/{id}', [AcademicSupportController::class, 'show'])->defaults('resource', 'routine-room');
            Route::match(['put', 'patch'], '/routine-rooms/{id}', [AcademicSupportController::class, 'update'])->defaults('resource', 'routine-room');
            Route::delete('/routine-rooms/{id}', [AcademicSupportController::class, 'destroy'])->defaults('resource', 'routine-room');

            // Section Management
            Route::apiResource('sections', SectionController::class);
            Route::get('/class-section-assignments', [AcademicAssignmentController::class, 'sections']);
            Route::post('/class-section-assignments', [AcademicAssignmentController::class, 'sectionStore']);
            Route::delete('/class-section-assignments/{id}', [AcademicAssignmentController::class, 'sectionDestroy']);

            // Subject Management
            Route::apiResource('subjects', SubjectController::class);
            Route::get('/class-subject-assignments', [AcademicAssignmentController::class, 'subjects']);
            Route::post('/class-subject-assignments', [AcademicAssignmentController::class, 'subjectStore']);
            Route::delete('/class-subject-assignments/{id}', [AcademicAssignmentController::class, 'subjectDestroy']);
            Route::post('/student-subject-assignments/bulk', [AcademicAssignmentController::class, 'bulkStore']);

            // Shift Management
            Route::apiResource('shifts', ShiftController::class);

            // Teacher Assignment Management
            Route::apiResource('teacher-assignments', TeacherAssignmentController::class);

            // Exam Management
            Route::apiResource('exams', ExamController::class);

            // Exam Subject Management
            Route::apiResource('exam-subjects', ExamSubjectController::class);

            // Fee Type Management
            Route::apiResource('fee-types', FeeTypeController::class);
            Route::get('/fee-categories', [FeeManagementController::class, 'categories']);
            Route::post('/fee-categories', [FeeManagementController::class, 'categoryStore']);
            Route::put('/fee-categories/{category}', [FeeManagementController::class, 'categoryUpdate']);
            Route::delete('/fee-categories/{category}', [FeeManagementController::class, 'categoryDestroy']);
            Route::get('/fee-items', [FeeManagementController::class, 'items']);
            Route::post('/fee-items', [FeeManagementController::class, 'itemStore']);
            Route::put('/fee-items/{item}', [FeeManagementController::class, 'itemUpdate']);
            Route::delete('/fee-items/{item}', [FeeManagementController::class, 'itemDestroy']);
            Route::get('/fee-pricings', [FeeManagementController::class, 'pricings']);
            Route::post('/fee-pricings', [FeeManagementController::class, 'pricingStore']);
            Route::get('/monthly-fee-setups', [FeeManagementController::class, 'monthly']);
            Route::post('/monthly-fee-setups', [FeeManagementController::class, 'monthlyStore']);
            Route::post('/student-fees/generate', [FeeManagementController::class, 'generateDue']);

            // Payment Management
            Route::apiResource('payments', PaymentController::class);

            // Routine Management
            Route::apiResource('routines', RoutineController::class)
                ->only(['store', 'update', 'destroy']);

            // Parent Management
            Route::get('/parents', [ParentController::class, 'index']);
            Route::get('/parents/{parent}', [ParentController::class, 'show']);
            Route::post('/parents/{parent}/students', [ParentController::class, 'attachStudent']);
            Route::delete('/parents/{parent}/students/{student}', [ParentController::class, 'detachStudent']);
        });
    });

});
