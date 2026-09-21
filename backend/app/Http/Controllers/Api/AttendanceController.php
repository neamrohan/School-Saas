<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date'],
            'student_id' => ['sometimes', 'integer'],
            'class_id' => ['sometimes', 'integer'],
            'section_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in($this->statuses())],
        ]);

        $query = Attendance::with([
            'student.user',
            'class',
            'section',
        ])->where('school_id', $request->user()->school_id);

        foreach (['date', 'student_id', 'class_id', 'section_id', 'status'] as $filter) {
            if (array_key_exists($filter, $filters)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        $this->applyTeacherScope($query, $request);

        return response()->json([
            'attendances' => $query->latest('date')->latest()->get(),
        ]);
    }

    public function students(Request $request)
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $schoolId = $request->user()->school_id;
        $schoolClass = SchoolClass::where('school_id', $schoolId)
            ->findOrFail($validated['class_id']);
        $section = Section::where('school_class_id', $schoolClass->id)
            ->findOrFail($validated['section_id']);

        $this->ensureManageAccess($request, $schoolClass->id, $section->id);

        $attendances = Attendance::where('school_id', $schoolId)
            ->where('class_id', $schoolClass->id)
            ->where('section_id', $section->id)
            ->whereDate('date', $validated['date'])
            ->get()
            ->keyBy('student_id');

        $students = Student::with('user')
            ->where('school_id', $schoolId)
            ->where('class_id', $schoolClass->id)
            ->where('section_id', $section->id)
            ->orderBy('student_id')
            ->orderBy('id')
            ->get()
            ->map(function (Student $student) use ($attendances) {
                $attendance = $attendances->get($student->id);

                return [
                    'student' => $student,
                    'attendance' => $attendance,
                ];
            });

        return response()->json([
            'class' => $schoolClass,
            'section' => $section,
            'date' => $validated['date'],
            'students' => $students,
        ]);
    }

    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'attendance_date' => ['required', 'date'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['required', Rule::in($this->statuses())],
            'records.*.remarks' => ['sometimes', 'nullable', 'string'],
        ]);

        $schoolId = $request->user()->school_id;
        $schoolClass = SchoolClass::where('school_id', $schoolId)
            ->findOrFail($validated['class_id']);
        $section = Section::where('school_class_id', $schoolClass->id)
            ->findOrFail($validated['section_id']);
        $this->ensureManageAccess($request, $schoolClass->id, $section->id);

        $studentIds = collect($validated['records'])->pluck('student_id');
        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $schoolClass->id)
            ->where('section_id', $section->id)
            ->whereIn('id', $studentIds)
            ->pluck('id');

        if ($students->count() !== $studentIds->unique()->count()) {
            abort(response()->json([
                'message' => 'Every student must belong to the selected school, class, and section.',
            ], 422));
        }

        $attendances = DB::transaction(function () use ($validated, $schoolId, $schoolClass, $section) {
            foreach ($validated['records'] as $record) {
                Attendance::updateOrCreate(
                    [
                        'school_id' => $schoolId,
                        'student_id' => $record['student_id'],
                        'date' => $validated['attendance_date'],
                    ],
                    [
                        'class_id' => $schoolClass->id,
                        'section_id' => $section->id,
                        'status' => $record['status'],
                        'remarks' => $record['remarks'] ?? null,
                    ],
                );
            }

            return Attendance::with(['student.user', 'class', 'section'])
                ->where('school_id', $schoolId)
                ->where('class_id', $schoolClass->id)
                ->where('section_id', $section->id)
                ->whereDate('date', $validated['attendance_date'])
                ->whereIn('student_id', $validated['records'] ? collect($validated['records'])->pluck('student_id') : [])
                ->get();
        });

        return response()->json([
            'message' => 'Attendance saved successfully.',
            'attendances' => $attendances,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->attendanceRules());

        $this->validateAttendanceRecords($request, $validated);
        $this->ensureManageAccess($request, $validated['class_id'], $validated['section_id']);
        $this->ensureUniqueAttendance($validated);

        $attendance = Attendance::create([
            ...$validated,
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => 'Attendance created successfully.',
            'attendance' => $attendance->load([
                'student.user',
                'class',
                'section',
            ]),
        ], 201);
    }

    public function show(Request $request, Attendance $attendance)
    {
        $this->ensureSchoolAccess($request, $attendance);

        return response()->json([
            'attendance' => $attendance->load([
                'student.user',
                'class',
                'section',
            ]),
        ]);
    }

    public function update(Request $request, Attendance $attendance)
    {
        $this->ensureSchoolAccess($request, $attendance);

        $validated = $request->validate($this->attendanceRules(true));
        $effective = [
            'student_id' => $validated['student_id'] ?? $attendance->student_id,
            'class_id' => $validated['class_id'] ?? $attendance->class_id,
            'section_id' => $validated['section_id'] ?? $attendance->section_id,
            'date' => $validated['date'] ?? $attendance->date->toDateString(),
        ];

        $this->validateAttendanceRecords($request, $effective);
        $this->ensureManageAccess($request, $effective['class_id'], $effective['section_id']);
        $this->ensureUniqueAttendance($effective, $attendance);

        $attendance->update($validated);

        return response()->json([
            'message' => 'Attendance updated successfully.',
            'attendance' => $attendance->fresh()->load([
                'student.user',
                'class',
                'section',
            ]),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance)
    {
        $this->ensureSchoolAccess($request, $attendance);
        $this->ensureManageAccess($request, $attendance->class_id, $attendance->section_id);

        $attendance->delete();

        return response()->json([
            'message' => 'Attendance deleted successfully.',
        ]);
    }

    private function attendanceRules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'student_id' => [$presence, 'integer', 'exists:students,id'],
            'class_id' => [$presence, 'integer', 'exists:school_classes,id'],
            'section_id' => [$presence, 'integer', 'exists:sections,id'],
            'date' => [$presence, 'date'],
            'status' => [$presence, Rule::in($this->statuses())],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    private function statuses(): array
    {
        return ['present', 'absent', 'late', 'excused'];
    }

    private function validateAttendanceRecords(Request $request, array $values): void
    {
        $schoolId = $request->user()->school_id;
        $student = Student::find($values['student_id']);
        $schoolClass = SchoolClass::find($values['class_id']);
        $section = Section::with('schoolClass')->find($values['section_id']);

        if ($student?->school_id !== $schoolId
            || $schoolClass?->school_id !== $schoolId
            || $section?->schoolClass?->school_id !== $schoolId) {
            $this->abortUnauthorizedSchoolAccess();
        }

        if ((int) $student->class_id !== (int) $schoolClass->id
            || (int) $student->section_id !== (int) $section->id
            || (int) $section->school_class_id !== (int) $schoolClass->id) {
            abort(response()->json([
                'message' => 'Student, class, and section do not match.',
            ], 422));
        }
    }

    private function ensureUniqueAttendance(
        array $values,
        ?Attendance $attendance = null
    ): void {
        $query = Attendance::where('student_id', $values['student_id'])
            ->whereDate('date', $values['date']);

        if ($attendance) {
            $query->where('id', '!=', $attendance->id);
        }

        if ($query->exists()) {
            abort(response()->json([
                'message' => 'Attendance already exists for this student on this date.',
            ], 422));
        }
    }

    private function ensureSchoolAccess(Request $request, Attendance $attendance): void
    {
        if ($attendance->school_id !== $request->user()->school_id) {
            $this->abortUnauthorizedSchoolAccess();
        }

        if ($request->user()->role === 'teacher') {
            $this->ensureTeacherAssignment(
                $request,
                $attendance->class_id,
                $attendance->section_id
            );
        }
    }

    private function ensureManageAccess(Request $request, int $classId, int $sectionId): void
    {
        if ($request->user()->role === 'school_admin') {
            return;
        }

        if ($request->user()->role === 'teacher') {
            $this->ensureTeacherAssignment($request, $classId, $sectionId);
            return;
        }

        abort(response()->json([
            'message' => 'Attendance management access required.',
        ], 403));
    }

    private function ensureTeacherAssignment(Request $request, int $classId, int $sectionId): void
    {
        $teacher = Teacher::where('user_id', $request->user()->id)
            ->where('school_id', $request->user()->school_id)
            ->first();

        if (! $teacher || ! TeacherAssignment::where('school_id', $request->user()->school_id)
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $classId)
            ->where('section_id', $sectionId)
            ->exists()) {
            $this->abortUnauthorizedSchoolAccess();
        }
    }

    private function applyTeacherScope($query, Request $request): void
    {
        if ($request->user()->role !== 'teacher') {
            return;
        }

        $teacher = Teacher::where('user_id', $request->user()->id)
            ->where('school_id', $request->user()->school_id)
            ->first();
        $assignments = $teacher
            ? TeacherAssignment::where('school_id', $request->user()->school_id)
                ->where('teacher_id', $teacher->id)
                ->get(['class_id', 'section_id'])
            : collect();

        $query->where(function ($assignmentQuery) use ($assignments) {
            if ($assignments->isEmpty()) {
                $assignmentQuery->whereRaw('1 = 0');
                return;
            }

            foreach ($assignments as $assignment) {
                $assignmentQuery->orWhere(function ($pairQuery) use ($assignment) {
                    $pairQuery->where('class_id', $assignment->class_id)
                        ->where('section_id', $assignment->section_id);
                });
            }
        });
    }

    private function abortUnauthorizedSchoolAccess(): never
    {
        abort(response()->json([
            'message' => 'Unauthorized school access.',
        ], 403));
    }
}