<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class TeacherController extends Controller
{
    private const PROFILE_RELATIONS = ['user', 'assignments.class', 'assignments.section', 'assignments.subject'];

    public function index(Request $request): JsonResponse
    {
        $teachers = Teacher::with(self::PROFILE_RELATIONS)
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json(['teachers' => $teachers]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $validated = $request->validate([
            'user_id' => ['sometimes', 'integer'],
            'name' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id')), 'nullable', 'string', 'max:255'],
            'email' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id') && ! $request->filled('username')), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id') && ! $request->filled('email')), 'nullable', 'string', 'max:80', Rule::unique('users', 'username')],
            'password' => [Rule::requiredIf(fn (): bool => ! $request->filled('user_id')), 'nullable', 'string', 'min:8', 'confirmed'],
            'employee_id' => ['nullable', 'string', 'max:255', Rule::unique('teachers', 'employee_id')->where(fn (Builder $query): Builder => $query->where('school_id', $admin->school_id))],
            'phone' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $this->validateAssignment($validated, $admin->school_id);

        $photoPath = $request->hasFile('profile_photo')
            ? $request->file('profile_photo')->store('teacher-photos', 'public')
            : null;

        try {
            $teacher = DB::transaction(function () use ($admin, $validated, $photoPath): Teacher {
                if (isset($validated['user_id'])) {
                    $user = User::whereKey($validated['user_id'])
                        ->where('school_id', $admin->school_id)
                        ->where('role', 'teacher')
                        ->first();

                    if (! $user) {
                        abort(response()->json(['message' => 'Teacher user not found in your school.'], 422));
                    }
                } else {
                    $user = User::create([
                        'name' => $validated['name'],
                        'email' => $validated['email'] ?? null,
                        'username' => $validated['username'] ?? null,
                        'password' => $validated['password'],
                        'school_id' => $admin->school_id,
                        'role' => 'teacher',
                        'is_active' => $validated['is_active'] ?? true,
                    ]);
                }

                $teacher = Teacher::create([
                    'user_id' => $user->id,
                    'school_id' => $admin->school_id,
                    'employee_id' => $validated['employee_id'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'designation' => $validated['designation'] ?? null,
                    'qualification' => $validated['qualification'] ?? null,
                    'joining_date' => $validated['joining_date'] ?? null,
                    'profile_photo_path' => $photoPath,
                ]);

                if (isset($validated['class_id'], $validated['section_id'], $validated['subject_id'])) {
                    TeacherAssignment::create([
                        'school_id' => $admin->school_id,
                        'teacher_id' => $teacher->id,
                        'class_id' => $validated['class_id'],
                        'section_id' => $validated['section_id'],
                        'subject_id' => $validated['subject_id'],
                    ]);
                }

                return $teacher;
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Teacher account created successfully.',
            'teacher' => $teacher->load(self::PROFILE_RELATIONS),
        ], 201);
    }

    public function show(Request $request, Teacher $teacher): JsonResponse
    {
        $this->checkSchoolAccess($request, $teacher);

        return response()->json(['teacher' => $teacher->load(self::PROFILE_RELATIONS)]);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $this->checkSchoolAccess($request, $teacher);
        $user = $teacher->user;
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('teachers', 'employee_id')->where(fn (Builder $query): Builder => $query->where('school_id', $teacher->school_id))->ignore($teacher->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'joining_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $accountUpdates = array_intersect_key($validated, array_flip(['name', 'email', 'username', 'is_active']));
        if (! empty($validated['password'])) {
            $accountUpdates['password'] = $validated['password'];
        }
        unset($accountUpdates['is_active']);
        $photoPath = $request->hasFile('profile_photo')
            ? $request->file('profile_photo')->store('teacher-photos', 'public')
            : null;
        $oldPhotoPath = $teacher->profile_photo_path;

        try {
            DB::transaction(function () use ($teacher, $validated, $accountUpdates, $photoPath): void {
                if (array_key_exists('is_active', $validated)) {
                    $accountUpdates['is_active'] = $validated['is_active'];
                }
                if ($accountUpdates !== []) {
                    $teacher->user->update($accountUpdates);
                }

                $profileUpdates = array_intersect_key($validated, array_flip(['employee_id', 'phone', 'designation', 'qualification', 'joining_date']));
                if ($photoPath) {
                    $profileUpdates['profile_photo_path'] = $photoPath;
                }
                if ($profileUpdates !== []) {
                    $teacher->update($profileUpdates);
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
            'message' => 'Teacher account updated successfully.',
            'teacher' => $teacher->fresh()->load(self::PROFILE_RELATIONS),
        ]);
    }

    public function destroy(Request $request, Teacher $teacher): JsonResponse
    {
        $this->checkSchoolAccess($request, $teacher);
        $teacher->user()->update(['is_active' => false]);

        return response()->json(['message' => 'Teacher account deactivated successfully.']);
    }

    private function validateAssignment(array $validated, int $schoolId): void
    {
        $assignmentFields = ['class_id', 'section_id', 'subject_id'];
        $hasAssignment = collect($assignmentFields)->contains(fn (string $field): bool => isset($validated[$field]));
        if (! $hasAssignment) {
            return;
        }

        foreach ($assignmentFields as $field) {
            if (! isset($validated[$field])) {
                abort(response()->json(['message' => 'Class, section, and subject are all required for an assignment.'], 422));
            }
        }

        $schoolClass = SchoolClass::where('school_id', $schoolId)->find($validated['class_id']);
        $section = Section::with('schoolClass')->find($validated['section_id']);
        $subject = Subject::where('school_id', $schoolId)->find($validated['subject_id']);

        if (! $schoolClass || ! $subject || ! $section || $section->schoolClass?->school_id !== $schoolId || $section->school_class_id !== $schoolClass->id) {
            abort(response()->json(['message' => 'The selected class, section, or subject does not belong to your school.'], 422));
        }
    }

    private function checkSchoolAccess(Request $request, Teacher $teacher): void
    {
        abort_unless($teacher->school_id === $request->user()->school_id, 404);
    }
}
