<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Mark;
use App\Models\Student;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Services\GradeCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarkController extends Controller
{
    public function __construct(private readonly GradeCalculator $gradeCalculator)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'exam_subject_id' => ['sometimes', 'integer'],
            'student_id' => ['sometimes', 'integer'],
        ]);

        $query = Mark::with([
            'examSubject.exam',
            'examSubject.subject',
            'student.user',
        ])->where('school_id', $request->user()->school_id);

        foreach (['exam_subject_id', 'student_id'] as $filter) {
            if (array_key_exists($filter, $filters)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        $this->applyTeacherScope($query, $request);

        return response()->json([
            'marks' => $query->latest()->get(),
        ]);
    }

    public function students(Request $request)
    {
        $data = $request->validate([
            'exam_subject_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
        ]);
        $examSubject = ExamSubject::with('exam')->where('school_id', $request->user()->school_id)->findOrFail($data['exam_subject_id']);
        if ((int) $examSubject->exam->class_id !== (int) $data['class_id']) {
            abort(response()->json(['message' => 'Exam subject does not belong to the selected class.'], 422));
        }
        $this->validateClassSection($request, (int) $data['class_id'], (int) $data['section_id']);
        $students = Student::with('user')->where('school_id', $request->user()->school_id)
            ->where('class_id', $data['class_id'])->where('section_id', $data['section_id'])->get();
        $marks = Mark::where('school_id', $request->user()->school_id)
            ->where('exam_subject_id', $examSubject->id)->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');
        return response()->json(['exam_subject' => $examSubject->load('subject'), 'students' => $students->map(fn ($student) => ['student' => $student, 'mark' => $marks->get($student->id)])]);
    }

    public function bulkStore(Request $request)
    {
        $data = $request->validate([
            'exam_subject_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.marks' => ['required', 'numeric', 'min:0'],
        ]);
        $examSubject = ExamSubject::with('exam')->where('school_id', $request->user()->school_id)->findOrFail($data['exam_subject_id']);
        if ((int) $examSubject->exam->class_id !== (int) $data['class_id']) {
            abort(response()->json(['message' => 'Exam subject does not belong to the selected class.'], 422));
        }
        $this->validateClassSection($request, (int) $data['class_id'], (int) $data['section_id']);
        $students = Student::where('school_id', $request->user()->school_id)
            ->where('class_id', $data['class_id'])->where('section_id', $data['section_id'])
            ->whereIn('id', collect($data['records'])->pluck('student_id'))->get()->keyBy('id');
        if ($students->count() !== count($data['records'])) {
            abort(response()->json(['message' => 'All students must belong to the selected class, section, and school.'], 422));
        }
        foreach ($students as $student) {
            $this->ensureManageAccess($request, $student, $examSubject);
        }
        foreach ($data['records'] as $record) {
            if ((float) $record['marks'] > (float) $examSubject->full_marks) {
                abort(response()->json([
                    'message' => 'Marks cannot exceed the exam subject full marks.',
                ], 422));
            }
        }
        $saved = DB::transaction(function () use ($data, $examSubject, $request) {
            foreach ($data['records'] as $record) {
                $calculated = $this->gradeCalculator->calculate($record['marks'], $examSubject->full_marks);
                Mark::updateOrCreate(
                    ['school_id' => $request->user()->school_id, 'exam_subject_id' => $examSubject->id, 'student_id' => $record['student_id']],
                    ['marks' => $record['marks'], ...$calculated],
                );
            }
            return Mark::with(['student.user', 'examSubject.subject'])->where('exam_subject_id', $examSubject->id)->whereIn('student_id', collect($data['records'])->pluck('student_id'))->get();
        });
        return response()->json(['message' => 'Marks saved successfully.', 'marks' => $saved]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $records = $this->validateMarkRecords($request, $validated);
        $this->ensureManageAccess($request, $records['student'], $records['examSubject']);
        $this->ensureUniqueMark($validated);

        $calculated = $this->gradeCalculator->calculate(
            $validated['marks'],
            $records['examSubject']->full_marks
        );
        $mark = Mark::create([
            ...$validated,
            ...$calculated,
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => 'Mark created successfully.',
            'mark' => $mark->load([
                'examSubject.exam',
                'examSubject.subject',
                'student.user',
            ]),
        ], 201);
    }

    public function show(Request $request, Mark $mark)
    {
        $this->checkSchoolAccess($request, $mark);
        $mark->load(['examSubject', 'student']);
        $this->ensureManageAccess($request, $mark->student, $mark->examSubject);

        return response()->json([
            'mark' => $mark->load([
                'examSubject.exam',
                'examSubject.subject',
                'student.user',
            ]),
        ]);
    }

    public function update(Request $request, Mark $mark)
    {
        $this->checkSchoolAccess($request, $mark);

        $validated = $request->validate($this->rules(true));
        $effective = [
            'exam_subject_id' => $validated['exam_subject_id'] ?? $mark->exam_subject_id,
            'student_id' => $validated['student_id'] ?? $mark->student_id,
            'marks' => $validated['marks'] ?? $mark->marks,
        ];
        $records = $this->validateMarkRecords($request, $effective);
        $this->ensureManageAccess($request, $records['student'], $records['examSubject']);
        $this->ensureUniqueMark($effective, $mark);

        $calculated = $this->gradeCalculator->calculate(
            $effective['marks'],
            $records['examSubject']->full_marks
        );
        $mark->update([
            ...$validated,
            ...$calculated,
        ]);

        return response()->json([
            'message' => 'Mark updated successfully.',
            'mark' => $mark->fresh()->load([
                'examSubject.exam',
                'examSubject.subject',
                'student.user',
            ]),
        ]);
    }

    public function destroy(Request $request, Mark $mark)
    {
        $this->checkSchoolAccess($request, $mark);
        $mark->load(['examSubject', 'student']);
        $this->ensureManageAccess($request, $mark->student, $mark->examSubject);

        $mark->delete();

        return response()->json([
            'message' => 'Mark deleted successfully.',
        ]);
    }

    public function result(Request $request, Student $student, Exam $exam)
    {
        if ($student->school_id !== $request->user()->school_id
            || $exam->school_id !== $request->user()->school_id) {
            $this->abortUnauthorizedSchoolAccess();
        }

        return response()->json($this->buildResult($request, $student, $exam));
    }

    public function results(Request $request)
    {
        $data = $request->validate([
            'exam_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
        ]);
        $schoolId = $request->user()->school_id;
        $exam = Exam::where('school_id', $schoolId)->findOrFail($data['exam_id']);
        if ((int) $exam->class_id !== (int) $data['class_id']) {
            abort(response()->json(['message' => 'Exam does not belong to the selected class.'], 422));
        }
        $this->validateClassSection($request, (int) $data['class_id'], (int) $data['section_id']);
        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'])
            ->with('user')
            ->get();

        return response()->json([
            'results' => $students->map(fn (Student $student) => $this->buildResult($request, $student, $exam))->values(),
        ]);
    }

    private function buildResult(Request $request, Student $student, Exam $exam): array
    {
        $examSubjects = ExamSubject::with([
            'subject',
            'marks' => fn ($query) => $query->where('student_id', $student->id),
        ])->where('school_id', $request->user()->school_id)
            ->where('exam_id', $exam->id)
            ->get();

        $totalFullMarks = (float) $examSubjects->sum('full_marks');
        $totalObtainedMarks = 0.0;
        $gradePoints = [];
        $allPassed = $examSubjects->isNotEmpty();

        $subjects = $examSubjects->map(function (ExamSubject $examSubject) use (&$totalObtainedMarks, &$gradePoints, &$allPassed) {
            $mark = $examSubject->marks->first();
            $obtainedMarks = $mark ? (float) $mark->marks : 0.0;
            $totalObtainedMarks += $obtainedMarks;

            if ($mark) {
                $gradePoints[] = (float) $mark->grade_point;
            }

            $passed = $mark !== null && $obtainedMarks >= (float) $examSubject->pass_marks;
            $allPassed = $allPassed && $passed;

            return [
                'subject' => $examSubject->subject,
                'full_marks' => (float) $examSubject->full_marks,
                'pass_marks' => (float) $examSubject->pass_marks,
                'obtained_marks' => $mark ? $obtainedMarks : null,
                'grade' => $mark?->grade,
                'grade_point' => $mark ? (float) $mark->grade_point : null,
                'passed' => $passed,
            ];
        })->values();

        $percentage = $totalFullMarks > 0
            ? ($totalObtainedMarks / $totalFullMarks) * 100
            : 0.0;

        return [
            'student' => $student->load('user'),
            'exam' => $exam,
            'subjects' => $subjects,
            'total_marks' => $totalFullMarks,
            'obtained_marks' => $totalObtainedMarks,
            'average' => round($percentage, 2),
            'percentage' => round($percentage, 2),
            'overall_gpa' => count($gradePoints) > 0
                ? round(array_sum($gradePoints) / count($gradePoints), 2)
                : null,
            'passed' => $allPassed,
        ];
    }

    private function validateClassSection(Request $request, int $classId, int $sectionId): void
    {
        $schoolId = $request->user()->school_id;
        if (! \App\Models\SchoolClass::where('school_id', $schoolId)->whereKey($classId)->exists()
            || ! Section::whereKey($sectionId)->where('school_class_id', $classId)
                ->whereHas('schoolClass', fn ($query) => $query->where('school_id', $schoolId))
                ->exists()) {
            abort(response()->json(['message' => 'Class and section must belong to your school.'], 422));
        }
    }

    private function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'exam_subject_id' => [$presence, 'integer', 'exists:exam_subjects,id'],
            'student_id' => [$presence, 'integer', 'exists:students,id'],
            'marks' => [$presence, 'numeric', 'min:0'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    private function validateMarkRecords(Request $request, array $values): array
    {
        $schoolId = $request->user()->school_id;
        $examSubject = ExamSubject::with(['exam', 'subject'])->find($values['exam_subject_id']);
        $student = Student::find($values['student_id']);

        if ($examSubject?->school_id !== $schoolId
            || $examSubject?->exam?->school_id !== $schoolId
            || $examSubject?->subject?->school_id !== $schoolId
            || $student?->school_id !== $schoolId) {
            $this->abortUnauthorizedSchoolAccess();
        }

        if ((float) $values['marks'] > (float) $examSubject->full_marks) {
            abort(response()->json([
                'message' => 'Marks cannot exceed the exam subject full marks.',
            ], 422));
        }

        if (! $student->class_id || ! $student->section_id) {
            abort(response()->json([
                'message' => 'Student must belong to a class and section.',
            ], 422));
        }

        return compact('examSubject', 'student');
    }

    private function ensureUniqueMark(array $values, ?Mark $mark = null): void
    {
        $query = Mark::where('exam_subject_id', $values['exam_subject_id'])
            ->where('student_id', $values['student_id']);

        if ($mark) {
            $query->where('id', '!=', $mark->id);
        }

        if ($query->exists()) {
            abort(response()->json([
                'message' => 'A mark already exists for this student and exam subject.',
            ], 422));
        }
    }

    private function ensureManageAccess(Request $request, Student $student, ExamSubject $examSubject): void
    {
        if ($request->user()->role === 'school_admin') {
            return;
        }

        if ($request->user()->role !== 'teacher') {
            abort(response()->json([
                'message' => 'Mark management access required.',
            ], 403));
        }

        $teacher = Teacher::where('user_id', $request->user()->id)
            ->where('school_id', $request->user()->school_id)
            ->first();

        if (! $teacher || ! TeacherAssignment::where('school_id', $request->user()->school_id)
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $student->class_id)
            ->where('section_id', $student->section_id)
            ->where('subject_id', $examSubject->subject_id)
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
                ->get(['class_id', 'section_id', 'subject_id'])
            : collect();

        $query->where(function ($assignmentQuery) use ($assignments) {
            if ($assignments->isEmpty()) {
                $assignmentQuery->whereRaw('1 = 0');
                return;
            }

            foreach ($assignments as $assignment) {
                $assignmentQuery->orWhere(function ($pairQuery) use ($assignment) {
                    $pairQuery->whereHas('student', function ($studentQuery) use ($assignment) {
                        $studentQuery->where('class_id', $assignment->class_id)
                            ->where('section_id', $assignment->section_id);
                    })->whereHas('examSubject', function ($examSubjectQuery) use ($assignment) {
                        $examSubjectQuery->where('subject_id', $assignment->subject_id);
                    });
                });
            }
        });
    }

    private function checkSchoolAccess(Request $request, Mark $mark): void
    {
        if ($mark->school_id !== $request->user()->school_id) {
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