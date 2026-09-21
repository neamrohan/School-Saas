<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Subject;
use App\Models\ClassSubjectAssignment;
use Illuminate\Http\Request;

class ExamSubjectController extends Controller
{
    public function index(Request $request)
    {
        $examSubjects = ExamSubject::with(['exam', 'subject'])
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'exam_subjects' => $examSubjects,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $this->validateSchoolRecords($request, $validated);
        $this->validatePassMarks($validated['full_marks'], $validated['pass_marks']);
        $this->ensureUniqueAssignment($validated);

        $examSubject = ExamSubject::create([
            ...$validated,
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => 'Exam subject created successfully.',
            'exam_subject' => $examSubject->load(['exam', 'subject']),
        ], 201);
    }

    public function show(Request $request, ExamSubject $examSubject)
    {
        $this->checkSchoolAccess($request, $examSubject);

        return response()->json([
            'exam_subject' => $examSubject->load(['exam', 'subject']),
        ]);
    }

    public function update(Request $request, ExamSubject $examSubject)
    {
        $this->checkSchoolAccess($request, $examSubject);

        $validated = $request->validate($this->rules(true));
        $effective = [
            'exam_id' => $validated['exam_id'] ?? $examSubject->exam_id,
            'subject_id' => $validated['subject_id'] ?? $examSubject->subject_id,
            'full_marks' => $validated['full_marks'] ?? $examSubject->full_marks,
            'pass_marks' => $validated['pass_marks'] ?? $examSubject->pass_marks,
        ];

        $this->validateSchoolRecords($request, $effective);
        $this->validatePassMarks($effective['full_marks'], $effective['pass_marks']);
        $this->ensureUniqueAssignment($effective, $examSubject);

        $examSubject->update($validated);

        return response()->json([
            'message' => 'Exam subject updated successfully.',
            'exam_subject' => $examSubject->fresh()->load(['exam', 'subject']),
        ]);
    }

    public function destroy(Request $request, ExamSubject $examSubject)
    {
        $this->checkSchoolAccess($request, $examSubject);

        $examSubject->delete();

        return response()->json([
            'message' => 'Exam subject deleted successfully.',
        ]);
    }

    private function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'exam_id' => [$presence, 'integer', 'exists:exams,id'],
            'subject_id' => [$presence, 'integer', 'exists:subjects,id'],
            'full_marks' => [$presence, 'numeric', 'min:0'],
            'pass_marks' => [$presence, 'numeric', 'min:0'],
        ];
    }

    private function validateSchoolRecords(Request $request, array $values): void
    {
        $schoolId = $request->user()->school_id;
        $exam = Exam::find($values['exam_id']);
        $subject = Subject::find($values['subject_id']);

        if ($exam?->school_id !== $schoolId || $subject?->school_id !== $schoolId) {
            $this->abortUnauthorizedSchoolAccess();
        }

        if ($exam->class_id && ! ClassSubjectAssignment::where('school_id', $schoolId)
            ->where('school_class_id', $exam->class_id)
            ->where('subject_id', $subject->id)
            ->exists()) {
            abort(response()->json(['message' => 'Subject is not assigned to this exam class.'], 422));
        }
    }

    private function validatePassMarks(float|int|string $fullMarks, float|int|string $passMarks): void
    {
        if ((float) $passMarks > (float) $fullMarks) {
            abort(response()->json([
                'message' => 'Pass marks cannot exceed full marks.',
            ], 422));
        }
    }

    private function ensureUniqueAssignment(
        array $values,
        ?ExamSubject $examSubject = null
    ): void {
        $query = ExamSubject::where('exam_id', $values['exam_id'])
            ->where('subject_id', $values['subject_id']);

        if ($examSubject) {
            $query->where('id', '!=', $examSubject->id);
        }

        if ($query->exists()) {
            abort(response()->json([
                'message' => 'This subject is already assigned to the exam.',
            ], 422));
        }
    }

    private function checkSchoolAccess(Request $request, ExamSubject $examSubject): void
    {
        if ($examSubject->school_id !== $request->user()->school_id) {
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