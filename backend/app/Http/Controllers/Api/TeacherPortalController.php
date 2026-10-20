<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSubject;
use App\Models\Routine;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;

class TeacherPortalController extends Controller
{
    public function assignments(Request $request)
    {
        $teacher = $this->teacherFor($request);
        $assignments = TeacherAssignment::with(['class', 'section', 'subject'])
            ->where('school_id', $request->user()->school_id)
            ->where('teacher_id', $teacher->id)
            ->orderBy('class_id')
            ->orderBy('section_id')
            ->orderBy('subject_id')
            ->get();

        return response()->json(['assignments' => $assignments]);
    }

    public function examSubjects(Request $request)
    {
        $teacher = $this->teacherFor($request);
        $assignments = TeacherAssignment::where('school_id', $request->user()->school_id)
            ->where('teacher_id', $teacher->id)
            ->get(['class_id', 'subject_id']);

        $query = ExamSubject::with(['exam.class', 'subject'])
            ->where('school_id', $request->user()->school_id);

        if ($assignments->isEmpty()) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where(function ($assignmentQuery) use ($assignments): void {
                foreach ($assignments as $assignment) {
                    $assignmentQuery->orWhere(function ($pairQuery) use ($assignment): void {
                        $pairQuery->where('subject_id', $assignment->subject_id)
                            ->whereHas('exam', fn ($examQuery) => $examQuery->where('class_id', $assignment->class_id));
                    });
                }
            });
        }

        return response()->json(['exam_subjects' => $query->latest()->get()]);
    }

    public function routines(Request $request)
    {
        $teacher = $this->teacherFor($request);
        $routines = Routine::with([
            'class',
            'section',
            'subject',
            'academicYear',
            'shift',
            'period',
            'roomEntity',
        ])
            ->where('school_id', $request->user()->school_id)
            ->where('teacher_id', $teacher->id)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json(['routines' => $routines]);
    }

    private function teacherFor(Request $request): Teacher
    {
        abort_unless($request->user()->role === 'teacher', 403, 'Teacher access required.');

        $teacher = Teacher::where('user_id', $request->user()->id)
            ->where('school_id', $request->user()->school_id)
            ->first();

        abort_unless($teacher, 403, 'Teacher profile not found.');

        return $teacher;
    }
}
