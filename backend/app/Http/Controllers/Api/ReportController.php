<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\Mark;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Services\MoneyCalculator;
use Illuminate\Http\Request;

class ReportController
{
    public function __construct(private readonly MoneyCalculator $money) {}

    public function students(Request $request)
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'integer'],
            'section_id' => ['sometimes', 'integer'],
            'gender' => ['sometimes', 'string', 'max:50'],
            'student_id' => ['sometimes', 'integer'],
        ]);
        $query = $this->studentScope($request)->with(['user', 'class', 'section']);
        foreach (['class_id', 'section_id', 'gender', 'student_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        return response()->json(['students' => $query->latest()->get()]);
    }

    public function attendance(Request $request)
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date'], 'date_from' => ['sometimes', 'date'], 'date_to' => ['sometimes', 'date'],
            'class_id' => ['sometimes', 'integer'], 'section_id' => ['sometimes', 'integer'], 'student_id' => ['sometimes', 'integer'],
        ]);
        $query = Attendance::with(['student.user', 'class', 'section'])->where('school_id', $request->user()->school_id);
        $this->applyStudentAccess($query, $request, 'student_id');
        foreach (['date', 'class_id', 'section_id', 'student_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        if (isset($filters['date_from'])) $query->whereDate('date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('date', '<=', $filters['date_to']);
        $records = $query->latest('date')->get();
        return response()->json(['summary' => $records->groupBy('status')->map->count(), 'attendances' => $records]);
    }

    public function fees(Request $request)
    {
        $filters = $request->validate([
            'student_id' => ['sometimes', 'integer'], 'fee_type_id' => ['sometimes', 'integer'], 'status' => ['sometimes', 'string'],
            'date_from' => ['sometimes', 'date'], 'date_to' => ['sometimes', 'date'],
        ]);
        $query = StudentFee::with(['student.user', 'feeType', 'payments'])->where('school_id', $request->user()->school_id);
        $this->applyStudentAccess($query, $request, 'student_id');
        foreach (['student_id', 'fee_type_id', 'status'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        if (isset($filters['date_from'])) $query->whereDate('due_date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('due_date', '<=', $filters['date_to']);
        $fees = $query->latest()->get();
        $total = $this->money->sum($fees->pluck('amount'));
        $paid = $this->money->sum($fees->flatMap->payments->pluck('amount'));
        return response()->json([
            'total_amount' => $this->money->formatCents($total), 'total_paid' => $this->money->formatCents($paid),
            'total_due' => $this->money->formatCents($total - $paid), 'fees' => $fees,
            'payments' => $fees->flatMap->payments->values(),
        ]);
    }

    public function results(Request $request)
    {
        $filters = $request->validate([
            'exam_id' => ['sometimes', 'integer'], 'class_id' => ['sometimes', 'integer'],
            'section_id' => ['sometimes', 'integer'], 'student_id' => ['sometimes', 'integer'],
        ]);
        $query = Mark::with(['examSubject.exam', 'examSubject.subject', 'student.user', 'student.class', 'student.section'])
            ->where('school_id', $request->user()->school_id);
        $this->applyStudentAccess($query, $request, 'student_id', 'student');
        foreach (['student_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        foreach (['class_id', 'section_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->whereHas('student', fn ($q) => $q->where($filter, $filters[$filter]));
        }
        if (isset($filters['exam_id'])) $query->whereHas('examSubject', fn ($q) => $q->where('exam_id', $filters['exam_id']));
        return response()->json(['results' => $query->latest()->get()]);
    }

    private function studentScope(Request $request)
    {
        $query = Student::where('school_id', $request->user()->school_id);
        $this->applyStudentAccess($query, $request);
        return $query;
    }

    private function applyStudentAccess($query, Request $request, string $column = 'id', string $relation = ''): void
    {
        $user = $request->user();
        if ($user->role === 'school_admin' || $user->role === 'super_admin') return;
        if ($user->role === 'student') {
            $studentId = $user->student?->id;
            if ($relation) {
                $query->whereHas($relation, fn ($studentQuery) => $studentQuery->whereKey($studentId ?? 0));
            } else {
                $query->where($column, $studentId ?? 0);
            }
            return;
        }
        if ($user->role === 'parent') {
            $ids = $user->children()->pluck('students.id');
            if ($relation) {
                $query->whereHas($relation, fn ($studentQuery) => $studentQuery->whereIn('id', $ids));
            } else {
                $query->whereIn($column, $ids);
            }
            return;
        }
        if ($user->role === 'teacher') {
            $teacher = Teacher::where('user_id', $user->id)->where('school_id', $user->school_id)->first();
            $assignments = $teacher ? TeacherAssignment::where('teacher_id', $teacher->id)->get() : collect();
            $query->where(function ($scope) use ($assignments, $relation) {
                foreach ($assignments as $assignment) {
                    $callback = function ($studentQuery) use ($assignment) {
                        $studentQuery->where('class_id', $assignment->class_id)->where('section_id', $assignment->section_id);
                    };
                    if ($relation) $scope->orWhereHas($relation, $callback);
                    else $scope->orWhere($callback);
                }
                if ($assignments->isEmpty()) $scope->whereRaw('1 = 0');
            });
        }
    }
}