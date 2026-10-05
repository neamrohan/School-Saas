<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GroupTrade;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class StudentController extends Controller
{
    private const PROFILE_RELATIONS = ['user', 'school', 'class', 'section', 'academicYear', 'shift', 'version', 'groupTrade'];

    public function index(Request $request): JsonResponse
    {
        $students = Student::with(self::PROFILE_RELATIONS)
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json(['students' => $students]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $validated = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'name' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id')), 'nullable', 'string', 'max:255'],
            'email' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id') && ! $request->filled('username')), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id') && ! $request->filled('email')), 'nullable', 'string', 'max:80', Rule::unique('users', 'username')],
            'password' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id')), 'nullable', 'string', 'min:8', 'confirmed'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['required', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'group_trade_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'string', 'max:255', Rule::unique('students', 'student_id')->where(fn (Builder $query): Builder => $query->where('school_id', $admin->school_id))],
            'roll' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'blood_group' => ['nullable', 'string', 'max:20'],
            'admission_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $this->validateAcademicPlacement($request, $validated);
        $this->validateAcademicContext($request, $validated);
        $photoPath = $request->hasFile('profile_photo')
            ? $request->file('profile_photo')->store('student-photos', 'public')
            : null;

        try {
            $student = DB::transaction(function () use ($admin, $validated, $photoPath): Student {
                if (isset($validated['user_id'])) {
                    $user = User::whereKey($validated['user_id'])
                        ->where('school_id', $admin->school_id)
                        ->where('role', 'student')
                        ->first();

                    if (! $user) {
                        abort(response()->json(['message' => 'Student user not found in your school.'], 422));
                    }
                    if (array_key_exists('name', $validated)) {
                        $user->update(['name' => $validated['name']]);
                    }
                } else {
                    $user = User::create([
                        'name' => $validated['name'],
                        'email' => $validated['email'] ?? null,
                        'username' => $validated['username'] ?? null,
                        'password' => $validated['password'],
                        'school_id' => $admin->school_id,
                        'role' => 'student',
                        'is_active' => $validated['is_active'] ?? true,
                    ]);
                }

                if (Student::where('user_id', $user->id)->exists()) {
                    abort(response()->json(['message' => 'Student profile already exists.'], 422));
                }

                $profileData = array_intersect_key($validated, array_flip([
                    'class_id', 'section_id', 'academic_year_id', 'shift_id', 'version_id',
                    'group_trade_id', 'student_id', 'roll', 'phone', 'date_of_birth',
                    'gender', 'address', 'blood_group', 'admission_date', 'is_active',
                ]));

                return Student::create([
                    ...$profileData,
                    'user_id' => $user->id,
                    'school_id' => $admin->school_id,
                    'profile_photo_path' => $photoPath,
                ]);
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Student account created successfully.',
            'student' => $student->load(self::PROFILE_RELATIONS),
        ], 201);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->checkSchoolAccess($request, $student);

        return response()->json(['student' => $student->load(self::PROFILE_RELATIONS)]);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $this->checkSchoolAccess($request, $student);
        $user = $student->user;
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'class_id' => ['sometimes', 'nullable', 'integer', 'exists:school_classes,id'],
            'section_id' => ['sometimes', 'nullable', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['sometimes', 'required', 'integer'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'version_id' => ['sometimes', 'nullable', 'integer'],
            'group_trade_id' => ['sometimes', 'nullable', 'integer'],
            'student_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('students', 'student_id')->where(fn (Builder $query): Builder => $query->where('school_id', $student->school_id))->ignore($student->id)],
            'roll' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'blood_group' => ['sometimes', 'nullable', 'string', 'max:20'],
            'admission_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $this->validateAcademicPlacement($request, $validated, $student);
        $this->validateAcademicContext($request, $validated, $student);
        $email = array_key_exists('email', $validated) ? $validated['email'] : $user->email;
        $username = array_key_exists('username', $validated) ? $validated['username'] : $user->username;
        if (! $email && ! $username) {
            abort(response()->json(['message' => 'An email address or username is required.'], 422));
        }

        $accountUpdates = array_intersect_key($validated, array_flip(['name', 'email', 'username', 'is_active']));
        if (! empty($validated['password'])) {
            $accountUpdates['password'] = $validated['password'];
        }
        $profileUpdates = array_intersect_key($validated, array_flip([
            'class_id', 'section_id', 'academic_year_id', 'shift_id', 'version_id',
            'group_trade_id', 'student_id', 'roll', 'phone', 'date_of_birth',
            'gender', 'address', 'blood_group', 'admission_date', 'is_active',
        ]));
        $photoPath = $request->hasFile('profile_photo')
            ? $request->file('profile_photo')->store('student-photos', 'public')
            : null;
        $oldPhotoPath = $student->profile_photo_path;

        try {
            DB::transaction(function () use ($student, $accountUpdates, $profileUpdates, $photoPath): void {
                if ($accountUpdates !== []) {
                    $student->user->update($accountUpdates);
                }
                if ($photoPath) {
                    $profileUpdates['profile_photo_path'] = $photoPath;
                }
                if ($profileUpdates !== []) {
                    $student->update($profileUpdates);
                }
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $exception;
        }

        if ($photoPath && $oldPhotoPath) {
            Storage::disk('public')->delete($oldPhotoPath);
        }

        return response()->json([
            'message' => 'Student account updated successfully.',
            'student' => $student->fresh()->load(self::PROFILE_RELATIONS),
        ]);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->checkSchoolAccess($request, $student);
        DB::transaction(function () use ($student): void {
            $student->user()->update(['is_active' => false]);
            $student->update(['is_active' => false]);
        });

        return response()->json(['message' => 'Student account deactivated successfully.']);
    }

    private function checkSchoolAccess(Request $request, Student $student): void
    {
        abort_unless($student->school_id === $request->user()->school_id, 404);
    }

    private function validateAcademicPlacement(Request $request, array $validated, ?Student $student = null): void
    {
        $classId = array_key_exists('class_id', $validated) ? $validated['class_id'] : $student?->class_id;
        $sectionId = array_key_exists('section_id', $validated) ? $validated['section_id'] : $student?->section_id;

        if ($classId !== null && ! SchoolClass::whereKey($classId)
            ->where('school_id', $request->user()->school_id)
            ->exists()) {
            abort(response()->json(['message' => 'Class not found in your school.'], 422));
        }

        if ($sectionId !== null && ($classId === null || ! Section::whereKey($sectionId)
            ->where('school_class_id', $classId)
            ->whereHas('schoolClass', fn ($query) => $query->where('school_id', $request->user()->school_id))
            ->exists())) {
            abort(response()->json(['message' => 'Section does not belong to the selected class and school.'], 422));
        }
    }

    private function validateAcademicContext(Request $request, array $validated, ?Student $student = null): void
    {
        $schoolId = $request->user()->school_id;
        $academicYearId = $validated['academic_year_id'] ?? $student?->academic_year_id;

        if (! AcademicYear::where('school_id', $schoolId)->whereKey($academicYearId)->exists()) {
            abort(response()->json(['message' => 'Academic year not found in your school.'], 422));
        }

        foreach ([
            'shift_id' => [Shift::class, 'Shift'],
            'version_id' => [Version::class, 'Version'],
            'group_trade_id' => [GroupTrade::class, 'Group/Trade'],
        ] as $field => [$model, $label]) {
            if (array_key_exists($field, $validated)
                && $validated[$field] !== null
                && ! $model::where('school_id', $schoolId)->whereKey($validated[$field])->exists()) {
                abort(response()->json(['message' => $label.' not found in your school.'], 422));
            }
        }
    }
}
