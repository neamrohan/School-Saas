<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\AcademicYear;
use App\Models\GroupTrade;
use App\Models\Shift;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $students = Student::with(['user', 'school', 'class', 'section', 'academicYear', 'shift', 'version', 'groupTrade'])
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'students' => $students,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'class_id' => ['nullable', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'exists:sections,id'],
            'academic_year_id' => ['required', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'group_trade_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'blood_group' => ['nullable', 'string', 'max:20'],
            'admission_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->validateAcademicPlacement($request, $validated);
        $this->validateAcademicContext($request, $validated);

        $user = isset($validated['user_id'])
            ? User::where('id', $validated['user_id'])
                ->where('school_id', $admin->school_id)
                ->where('role', 'student')
                ->first()
            : User::create([
                'name' => $validated['name'],
                'email' => 'student+'.Str::uuid().'@school-saas.local',
                'password' => Hash::make(Str::random(32)),
                'school_id' => $admin->school_id,
                'role' => 'student',
            ]);

        if (! $user) {
            return response()->json(['message' => 'Student user not found in your school.'], 422);
        }

        if (Student::where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Student profile already exists.',
            ], 422);
        }

        unset($validated['name']);
        unset($validated['user_id']);
        $user->update(['name' => $request->string('name')->toString()]);
        $student = Student::create([
            ...$validated,
            'user_id' => $user->id,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Student profile created successfully.',
            'student' => $student->load(['user', 'school', 'class', 'section', 'academicYear', 'shift', 'version', 'groupTrade']),
        ], 201);
    }

    public function show(Request $request, Student $student)
    {
        $this->checkSchoolAccess($request, $student);

        return response()->json([
            'student' => $student->load(['user', 'school', 'class', 'section', 'academicYear', 'shift', 'version', 'groupTrade']),
        ]);
    }

    public function update(Request $request, Student $student)
    {
        $this->checkSchoolAccess($request, $student);

        $validated = $request->validate([
            'class_id' => ['sometimes', 'nullable', 'exists:school_classes,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'section_id' => ['sometimes', 'nullable', 'exists:sections,id'],
            'academic_year_id' => ['sometimes', 'required', 'integer'],
            'shift_id' => ['sometimes', 'nullable', 'integer'],
            'version_id' => ['sometimes', 'nullable', 'integer'],
            'group_trade_id' => ['sometimes', 'nullable', 'integer'],
            'student_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'blood_group' => ['nullable', 'string', 'max:20'],
            'admission_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->validateAcademicPlacement($request, $validated, $student);
        $this->validateAcademicContext($request, $validated, $student);

        if (array_key_exists('name', $validated)) {
            $student->user()->update(['name' => $validated['name']]);
            unset($validated['name']);
        }
        $student->update($validated);

        return response()->json([
            'message' => 'Student profile updated successfully.',
            'student' => $student->fresh()->load(['user', 'school', 'class', 'section', 'academicYear', 'shift', 'version', 'groupTrade']),
        ]);
    }

    public function destroy(Request $request, Student $student)
    {
        $this->checkSchoolAccess($request, $student);

        $student->delete();

        return response()->json([
            'message' => 'Student profile deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, Student $student): void
    {
        if ($student->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }

    private function validateAcademicPlacement(
        Request $request,
        array $validated,
        ?Student $student = null
    ): void {
        $classId = array_key_exists('class_id', $validated)
            ? $validated['class_id']
            : $student?->class_id;
        $sectionId = array_key_exists('section_id', $validated)
            ? $validated['section_id']
            : $student?->section_id;

        if ($classId !== null && ! SchoolClass::whereKey($classId)
            ->where('school_id', $request->user()->school_id)
            ->exists()) {
            abort(response()->json([
                'message' => 'Class not found in your school.',
            ], 422));
        }

        if ($sectionId !== null && ($classId === null || ! Section::whereKey($sectionId)
            ->where('school_class_id', $classId)
            ->whereHas('schoolClass', function ($query) use ($request) {
                $query->where('school_id', $request->user()->school_id);
            })
            ->exists())) {
            abort(response()->json([
                'message' => 'Section does not belong to the selected class and school.',
            ], 422));
        }
    }

    private function validateAcademicContext(
        Request $request,
        array $validated,
        ?Student $student = null
    ): void {
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