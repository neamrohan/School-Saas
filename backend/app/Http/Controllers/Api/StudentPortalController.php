<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Mark;
use App\Models\Routine;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPortalController extends Controller
{
    public function classes(Request $request): JsonResponse
    {
        $student = $this->student($request->user())->load(['school', 'class', 'section', 'academicYear']);
        $subjects = $student->class_id
            ? Subject::query()
                ->join('class_subject_assignments', 'subjects.id', '=', 'class_subject_assignments.subject_id')
                ->where('class_subject_assignments.school_id', $student->school_id)
                ->where('class_subject_assignments.school_class_id', $student->class_id)
                ->where('subjects.school_id', $student->school_id)
                ->select('subjects.*')
                ->orderBy('subjects.name')
                ->get()
            : collect();

        return response()->json(['student' => $student, 'subjects' => $subjects]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $student = $this->student($request->user());
        $attendance = Attendance::with(['class', 'section'])
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->orderByDesc('date')
            ->get();

        return response()->json(['attendance' => $attendance]);
    }

    public function results(Request $request): JsonResponse
    {
        $student = $this->student($request->user());
        $marks = Mark::with(['examSubject.exam', 'examSubject.subject'])
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json(['marks' => $marks]);
    }

    public function routine(Request $request): JsonResponse
    {
        $student = $this->student($request->user());
        if (! $student->class_id || ! $student->section_id) {
            return response()->json(['routines' => []]);
        }

        $routines = Routine::with(['class', 'section', 'subject', 'teacher.user', 'academicYear', 'shift', 'period', 'roomEntity'])
            ->where('school_id', $student->school_id)
            ->where('class_id', $student->class_id)
            ->where('section_id', $student->section_id)
            ->where('is_active', true)
            ->orderByRaw("CASE day_of_week WHEN 'saturday' THEN 1 WHEN 'sunday' THEN 2 WHEN 'monday' THEN 3 WHEN 'tuesday' THEN 4 WHEN 'wednesday' THEN 5 WHEN 'thursday' THEN 6 WHEN 'friday' THEN 7 ELSE 8 END")
            ->orderBy('start_time')
            ->get();

        return response()->json(['routines' => $routines]);
    }

    public function fees(Request $request): JsonResponse
    {
        $student = $this->student($request->user());
        $fees = StudentFee::with(['feeType', 'feeItem', 'academicYear', 'payments'])
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        return response()->json(['student_fees' => $fees]);
    }

    private function student(User $user): Student
    {
        abort_unless($user->role === 'student' && $user->school_id, 403);

        return Student::query()
            ->where('user_id', $user->id)
            ->where('school_id', $user->school_id)
            ->firstOrFail();
    }
}
