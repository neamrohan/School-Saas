<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\OnlineAdmissionApplication;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentReAdmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentInformationController extends Controller
{
    public function applyOnline(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'guardian_name' => ['required', 'string', 'max:255'],
            'guardian_phone' => ['required', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'academic_year_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
            'section_id' => ['nullable', 'integer'],
        ]);
        $this->validatePlacement($school->id, $data['academic_year_id'], $data['class_id'], $data['section_id'] ?? null);
        abort_if(
            OnlineAdmissionApplication::where('school_id', $school->id)
                ->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])
                ->where('status', 'pending')
                ->exists(),
            422,
            'A pending application already exists for this email address.'
        );

        $application = OnlineAdmissionApplication::create([
            ...$data,
            'school_id' => $school->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Your admission application has been submitted for review.',
            'application' => ['id' => $application->id, 'status' => $application->status],
        ], 201);
    }

    public function admissionAcademicYears(string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();

        return response()->json([
            'academic_years' => AcademicYear::where('school_id', $school->id)
                ->where('is_active', true)->orderByDesc('is_current')->orderBy('name')->get(['id', 'name']),
        ])->header('Cache-Control', 'no-store');
    }

    public function admissionClasses(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();
        $data = $request->validate(['academic_year_id' => ['required', 'integer']]);
        abort_unless(AcademicYear::where('school_id', $school->id)->where('is_active', true)->whereKey($data['academic_year_id'])->exists(), 404);

        return response()->json([
            'classes' => SchoolClass::where('school_id', $school->id)->where('is_active', true)->orderBy('numeric_order')->orderBy('name')->get(['id', 'name']),
        ])->header('Cache-Control', 'no-store');
    }

    public function admissionSections(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();
        $data = $request->validate(['class_id' => ['required', 'integer']]);
        abort_unless(SchoolClass::where('school_id', $school->id)->where('is_active', true)->whereKey($data['class_id'])->exists(), 404);

        return response()->json([
            'sections' => Section::where('school_class_id', $data['class_id'])->where('is_active', true)->orderBy('name')->get(['id', 'school_class_id', 'name']),
        ])->header('Cache-Control', 'no-store');
    }

    public function applications(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OnlineAdmissionApplication::with(['academicYear', 'class', 'section', 'reviewer', 'approvedStudent'])
            ->where('school_id', $request->user()->school_id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['search']), fn ($q) => $q->where(function ($search) use ($filters): void {
                $search->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('email', 'like', '%'.$filters['search'].'%');
            }))
            ->latest();

        return response()->json(['applications' => $query->paginate(20)]);
    }

    public function reviewApplication(Request $request, OnlineAdmissionApplication $application): JsonResponse
    {
        $this->checkApplication($request, $application);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($application->status === 'pending', 409, 'Only pending applications can be reviewed.');

        $temporaryPassword = null;
        $student = DB::transaction(function () use ($request, $application, $data, &$temporaryPassword): ?Student {
            $application = OnlineAdmissionApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($application->status === 'pending', 409, 'This application has already been reviewed.');

            if ($data['decision'] === 'reject') {
                $application->update([
                    'status' => 'rejected',
                    'review_note' => $data['review_note'] ?? null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
                return null;
            }

            $existingUser = User::whereRaw('LOWER(email) = ?', [Str::lower($application->email)])->first();
            if ($existingUser && ($existingUser->school_id !== $application->school_id
                || $existingUser->role !== 'student'
                || Student::where('user_id', $existingUser->id)->exists())) {
                abort(422, 'This email already belongs to an account that cannot be linked to this application.');
            }

            if ($existingUser) {
                $user = $existingUser;
            } else {
                $temporaryPassword = Str::random(48);
                $user = User::create([
                    'name' => $application->name,
                    'email' => $application->email,
                    'username' => $this->uniqueUsername($application->email, $application->id),
                    'password' => $temporaryPassword,
                    'school_id' => $application->school_id,
                    'role' => 'student',
                ]);
            }

            $student = Student::create([
                'user_id' => $user->id,
                'school_id' => $application->school_id,
                'student_id' => 'ADM-'.$application->school_id.'-'.$application->id,
                'phone' => $application->phone,
                'date_of_birth' => $application->date_of_birth,
                'gender' => $application->gender,
                'address' => $application->address,
                'admission_date' => today(),
                'academic_year_id' => $application->academic_year_id,
                'class_id' => $application->class_id,
                'section_id' => $application->section_id,
                'is_active' => true,
            ]);

            $application->update([
                'status' => 'approved',
                'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'approved_student_id' => $student->id,
            ]);

            return $student->load(['user', 'class', 'section', 'academicYear']);
        });

        return response()->json([
            'message' => $data['decision'] === 'approve' ? 'Application approved and student admitted.' : 'Application rejected.',
            'student' => $student,
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function reAdmissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = StudentReAdmission::with(['student.user', 'previousAcademicYear', 'academicYear', 'creator'])
            ->where('school_id', $request->user()->school_id)
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['academic_year_id']), fn ($q) => $q->where('academic_year_id', $filters['academic_year_id']))
            ->latest();

        return response()->json(['re_admissions' => $query->paginate(20)]);
    }

    public function reAdmit(Request $request, Student $student): JsonResponse
    {
        abort_unless($student->school_id === $request->user()->school_id, 404);
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
        ]);
        $this->validatePlacement(
            $request->user()->school_id,
            $data['academic_year_id'],
            $data['class_id'] ?? null,
            $data['section_id'] ?? null
        );
        abort_if((int) $student->academic_year_id === (int) $data['academic_year_id'], 422, 'Student is already enrolled in this academic session.');

        $record = DB::transaction(function () use ($request, $student, $data): StudentReAdmission {
            $lockedStudent = Student::query()->where('school_id', $request->user()->school_id)
                ->lockForUpdate()->findOrFail($student->id);
            abort_if(
                StudentReAdmission::where('student_id', $lockedStudent->id)
                    ->where('academic_year_id', $data['academic_year_id'])->exists(),
                422,
                'Student already has a re-admission record for this academic session.'
            );
            $record = StudentReAdmission::create([
                'school_id' => $lockedStudent->school_id,
                'student_id' => $lockedStudent->id,
                'previous_academic_year_id' => $lockedStudent->academic_year_id,
                'academic_year_id' => $data['academic_year_id'],
                'previous_class_id' => $lockedStudent->class_id,
                'class_id' => $data['class_id'] ?? null,
                'previous_section_id' => $lockedStudent->section_id,
                'section_id' => $data['section_id'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $lockedStudent->update([
                'academic_year_id' => $data['academic_year_id'],
                'class_id' => $data['class_id'] ?? null,
                'section_id' => $data['section_id'] ?? null,
                'is_active' => true,
            ]);
            $lockedStudent->user()->update(['is_active' => true]);

            return $record->load(['student.user', 'previousAcademicYear', 'academicYear', 'creator']);
        });

        return response()->json(['message' => 'Student re-admitted successfully.', 're_admission' => $record], 201);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['required', 'integer', 'distinct'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
            'class_id' => ['sometimes', 'nullable', 'integer'],
            'section_id' => ['sometimes', 'nullable', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $updates = array_diff_key($data, ['student_ids' => true]);
        abort_if($updates === [], 422, 'Select at least one profile field to update.');

        $schoolId = $request->user()->school_id;
        $students = Student::where('school_id', $schoolId)->whereIn('id', $data['student_ids'])->get();
        abort_unless($students->count() === count($data['student_ids']), 404, 'One or more students were not found in your school.');
        if (isset($updates['academic_year_id'])) {
            abort_unless(AcademicYear::where('school_id', $schoolId)->whereKey($updates['academic_year_id'])->exists(), 422, 'Academic year not found in your school.');
        }
        if (array_key_exists('class_id', $updates) || array_key_exists('section_id', $updates)) {
            $classId = array_key_exists('class_id', $updates) ? $updates['class_id'] : $students->first()?->class_id;
            $sectionId = $updates['section_id'] ?? null;
            if ($classId !== null) {
                abort_unless(SchoolClass::where('school_id', $schoolId)->whereKey($classId)->exists(), 422, 'Class not found in your school.');
            }
            if ($sectionId !== null) {
                abort_if(
                    ! array_key_exists('class_id', $updates)
                        && $students->pluck('class_id')->unique()->count() !== 1,
                    422,
                    'Select students from one class or specify the target class when changing sections.'
                );
                abort_unless($classId !== null && Section::where('school_class_id', $classId)
                    ->whereHas('schoolClass', fn ($query) => $query->where('school_id', $schoolId))
                    ->whereKey($sectionId)->exists(), 422, 'Section does not belong to the selected class and school.');
            }
        }

        if (isset($updates['class_id']) && ! array_key_exists('section_id', $updates)) {
            $updates['section_id'] = null;
        }

        DB::transaction(function () use ($students, $updates): void {
            foreach ($students as $student) {
                $profileUpdates = array_intersect_key($updates, array_flip([
                    'phone', 'address', 'gender', 'class_id', 'section_id', 'academic_year_id', 'is_active',
                ]));
                $student->update($profileUpdates);
                if (array_key_exists('is_active', $updates)) {
                    $student->user()->update(['is_active' => $updates['is_active']]);
                }
            }
        });

        return response()->json([
            'message' => 'Student profiles updated successfully.',
            'updated' => $students->count(),
            'student_ids' => $students->modelKeys(),
        ]);
    }

    private function validatePlacement(int $schoolId, int $academicYearId, ?int $classId, ?int $sectionId): void
    {
        abort_unless(AcademicYear::where('school_id', $schoolId)->whereKey($academicYearId)->exists(), 422, 'Academic session not found in this school.');
        if ($classId !== null) {
            abort_unless(SchoolClass::where('school_id', $schoolId)->whereKey($classId)->exists(), 422, 'Class not found in this school.');
        }
        if ($sectionId !== null) {
            abort_unless($classId !== null && Section::where('school_class_id', $classId)
                ->whereHas('schoolClass', fn ($query) => $query->where('school_id', $schoolId))
                ->whereKey($sectionId)->exists(), 422, 'Section does not belong to the selected class and school.');
        }
    }

    private function checkApplication(Request $request, OnlineAdmissionApplication $application): void
    {
        abort_unless($application->school_id === $request->user()->school_id, 404);
    }

    private function uniqueUsername(string $email, int $applicationId): string
    {
        $base = Str::slug(Str::before($email, '@'), '_');
        $candidate = substr($base ?: 'student', 0, 65).'_'.$applicationId;
        $suffix = 1;
        while (User::where('username', $candidate)->exists()) {
            $candidate = substr($base ?: 'student', 0, 65).'_'.$applicationId.'_'.$suffix++;
        }

        return $candidate;
    }
}
