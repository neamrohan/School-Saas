<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;

class TeacherAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $assignments = TeacherAssignment::with([
            'teacher.user',
            'class',
            'section',
            'subject',
        ])
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'assignments' => $assignments,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'class_id' => ['required', 'exists:school_classes,id'],
            'section_id' => ['required', 'exists:sections,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
        ]);

        $this->validateAssignmentRecords($request, $validated);
        $this->ensureUniqueAssignment($validated);

        $assignment = TeacherAssignment::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Teacher assignment created successfully.',
            'assignment' => $assignment->load([
                'teacher.user',
                'class',
                'section',
                'subject',
            ]),
        ], 201);
    }

    public function show(Request $request, TeacherAssignment $teacherAssignment)
    {
        $this->checkSchoolAccess($request, $teacherAssignment);

        return response()->json([
            'assignment' => $teacherAssignment->load([
                'teacher.user',
                'class',
                'section',
                'subject',
            ]),
        ]);
    }

    public function update(Request $request, TeacherAssignment $teacherAssignment)
    {
        $this->checkSchoolAccess($request, $teacherAssignment);

        $validated = $request->validate([
            'teacher_id' => ['sometimes', 'required', 'exists:teachers,id'],
            'class_id' => ['sometimes', 'required', 'exists:school_classes,id'],
            'section_id' => ['sometimes', 'required', 'exists:sections,id'],
            'subject_id' => ['sometimes', 'required', 'exists:subjects,id'],
        ]);

        $this->validateAssignmentRecords($request, $validated, $teacherAssignment);
        $this->ensureUniqueAssignment($validated, $teacherAssignment);

        $teacherAssignment->update($validated);

        return response()->json([
            'message' => 'Teacher assignment updated successfully.',
            'assignment' => $teacherAssignment->fresh()->load([
                'teacher.user',
                'class',
                'section',
                'subject',
            ]),
        ]);
    }

    public function destroy(Request $request, TeacherAssignment $teacherAssignment)
    {
        $this->checkSchoolAccess($request, $teacherAssignment);

        $teacherAssignment->delete();

        return response()->json([
            'message' => 'Teacher assignment deleted successfully.',
        ]);
    }

    private function validateAssignmentRecords(
        Request $request,
        array $validated,
        ?TeacherAssignment $assignment = null
    ): void {
        $schoolId = $request->user()->school_id;
        $teacherId = $validated['teacher_id'] ?? $assignment?->teacher_id;
        $classId = $validated['class_id'] ?? $assignment?->class_id;
        $sectionId = $validated['section_id'] ?? $assignment?->section_id;
        $subjectId = $validated['subject_id'] ?? $assignment?->subject_id;

        $teacher = Teacher::find($teacherId);
        $schoolClass = SchoolClass::find($classId);
        $section = Section::with('schoolClass')->find($sectionId);
        $subject = Subject::find($subjectId);

        foreach ([$teacher, $schoolClass, $subject] as $record) {
            if ($record && $record->school_id !== $schoolId) {
                $this->abortUnauthorizedSchoolAccess();
            }
        }

        if ($section && $section->schoolClass?->school_id !== $schoolId) {
            $this->abortUnauthorizedSchoolAccess();
        }

        if ($section && $section->school_class_id !== (int) $classId) {
            abort(response()->json([
                'message' => 'Section does not belong to the selected class.',
            ], 422));
        }
    }

    private function ensureUniqueAssignment(
        array $validated,
        ?TeacherAssignment $assignment = null
    ): void {
        $query = TeacherAssignment::query()
            ->where('teacher_id', $validated['teacher_id'] ?? $assignment?->teacher_id)
            ->where('class_id', $validated['class_id'] ?? $assignment?->class_id)
            ->where('section_id', $validated['section_id'] ?? $assignment?->section_id)
            ->where('subject_id', $validated['subject_id'] ?? $assignment?->subject_id);

        if ($assignment) {
            $query->where('id', '!=', $assignment->id);
        }

        if ($query->exists()) {
            abort(response()->json([
                'message' => 'This teacher assignment already exists.',
            ], 422));
        }
    }

    private function checkSchoolAccess(Request $request, TeacherAssignment $assignment): void
    {
        if ($assignment->school_id !== $request->user()->school_id) {
            $this->abortUnauthorizedSchoolAccess();
        }
    }

    private function abortUnauthorizedSchoolAccess(): never
    {
        abort(response()->json([
            'message' => 'Unauthorized school access.',
        ], 403));
    }
}