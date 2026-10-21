<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\Mark;
use App\Models\Payment;
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
            'academic_year_id' => ['sometimes', 'integer'],
        ]);
        $query = Attendance::with(['student.user', 'class', 'section'])->where('school_id', $request->user()->school_id);
        $this->applyStudentAccess($query, $request, 'student_id');
        foreach (['class_id', 'section_id', 'student_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        if (isset($filters['date'])) $query->whereDate('date', $filters['date']);
        if (isset($filters['date_from'])) $query->whereDate('date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('date', '<=', $filters['date_to']);
        if (isset($filters['academic_year_id'])) {
            $query->whereHas('student', fn ($student) => $student->where('academic_year_id', $filters['academic_year_id']));
        }
        $records = $query->latest('date')->get();
        return response()->json(['summary' => $records->groupBy('status')->map->count(), 'attendances' => $records]);
    }

    public function admissions(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date'], 'date_to' => ['sometimes', 'date'],
            'class_id' => ['sometimes', 'integer'], 'academic_year_id' => ['sometimes', 'integer'],
        ]);
        $query = Student::with(['user', 'class', 'academicYear'])
            ->where('school_id', $request->user()->school_id);
        if (isset($filters['date_from'])) $query->whereDate('admission_date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('admission_date', '<=', $filters['date_to']);
        foreach (['class_id', 'academic_year_id'] as $filter) {
            if (isset($filters[$filter])) $query->where($filter, $filters[$filter]);
        }
        $students = $query->latest('admission_date')->get();
        $sessionCounts = $students->groupBy(fn (Student $student) => $student->academicYear?->name ?? 'No session')
            ->map(fn ($sessionStudents, $session) => ['session' => $session, 'count' => $sessionStudents->count()])
            ->values();

        return response()->json([
            'total_admissions' => $students->count(),
            'session_counts' => $sessionCounts,
            'admissions' => $students,
        ]);
    }

    public function admissionCollections(Request $request)
    {
        $filters = $this->paymentFilters($request);
        $payments = $this->paymentQuery($request, $filters, admissionOnly: true)->latest('payment_date')->get();

        return $this->paymentResponse($payments);
    }

    public function collections(Request $request)
    {
        $filters = $this->paymentFilters($request);
        $payments = $this->paymentQuery($request, $filters)->latest('payment_date')->get();

        return $this->paymentResponse($payments);
    }

    public function monthlyCollections(Request $request)
    {
        $filters = $this->paymentFilters($request);
        $payments = $this->paymentQuery($request, $filters)->get();
        $months = $payments->groupBy(fn (Payment $payment) => $payment->payment_date->format('Y-m'))
            ->map(fn ($monthPayments, $month) => [
                'month' => $month,
                'payment_count' => $monthPayments->count(),
                'total_collected' => $this->money->formatCents($this->money->sum($monthPayments->pluck('amount'))),
            ])->sortKeysDesc()->values();

        return response()->json([
            'payment_count' => $payments->count(),
            'total_collected' => $this->money->formatCents($this->money->sum($payments->pluck('amount'))),
            'months' => $months,
        ]);
    }

    public function cashBook(Request $request)
    {
        $filters = $this->paymentFilters($request);
        $payments = $this->paymentQuery($request, $filters, book: 'cash')->latest('payment_date')->get();

        return $this->paymentResponse($payments);
    }

    public function bankBook(Request $request)
    {
        $filters = $this->paymentFilters($request);
        $payments = $this->paymentQuery($request, $filters, book: 'bank')->latest('payment_date')->get();

        return $this->paymentResponse($payments);
    }

    public function fees(Request $request)
    {
        $filters = $request->validate([
            'student_id' => ['sometimes', 'integer'], 'fee_type_id' => ['sometimes', 'integer'], 'status' => ['sometimes', 'string'],
            'date_from' => ['sometimes', 'date'], 'date_to' => ['sometimes', 'date'],
            'class_id' => ['sometimes', 'integer'], 'academic_year_id' => ['sometimes', 'integer'],
        ]);
        $query = StudentFee::with(['student.user', 'student.class', 'feeType', 'feeItem', 'payments'])
            ->with(['waivers' => fn ($waivers) => $waivers->where('status', 'active')])
            ->where('school_id', $request->user()->school_id)
            ->whereHas('student', fn ($student) => $student->where('school_id', $request->user()->school_id));
        $this->applyStudentAccess($query, $request, 'student_id');
        foreach (['student_id', 'fee_type_id', 'status', 'academic_year_id'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        if (isset($filters['class_id'])) {
            $query->whereHas('student', fn ($student) => $student->where('class_id', $filters['class_id']));
        }
        if (isset($filters['date_from'])) $query->whereDate('due_date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('due_date', '<=', $filters['date_to']);
        $fees = $query->latest()->get();
        $total = $this->money->sum($fees->pluck('amount'));
        $paid = $this->money->sum($fees->flatMap->payments->pluck('amount'));
        $waived = $this->money->sum($fees->flatMap->waivers->pluck('amount'));
        return response()->json([
            'total_amount' => $this->money->formatCents($total), 'total_paid' => $this->money->formatCents($paid),
            'total_waived' => $this->money->formatCents($waived),
            'total_due' => $this->money->formatCents(max(0, $total - $paid - $waived)), 'fees' => $fees,
            'payments' => $fees->flatMap->payments->values(),
        ]);
    }

    private function paymentFilters(Request $request): array
    {
        $dateToRules = ['sometimes', 'date'];
        if ($request->filled('date_from')) $dateToRules[] = 'after_or_equal:date_from';

        return $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'class_id' => ['sometimes', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:paid,partial,unpaid'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => $dateToRules,
        ]);
    }

    private function paymentQuery(Request $request, array $filters, ?string $book = null, bool $admissionOnly = false)
    {
        $query = Payment::with(['student.user', 'student.class', 'studentFee.feeType', 'studentFee.feeItem.category'])
            ->where('payments.school_id', $request->user()->school_id)
            ->whereHas('student', fn ($student) => $student->where('school_id', $request->user()->school_id))
            ->whereHas('studentFee', fn ($fee) => $fee->where('school_id', $request->user()->school_id));
        if (isset($filters['date_from'])) $query->whereDate('payment_date', '>=', $filters['date_from']);
        if (isset($filters['date_to'])) $query->whereDate('payment_date', '<=', $filters['date_to']);
        if (isset($filters['student_id'])) $query->where('student_id', $filters['student_id']);
        if (isset($filters['class_id'])) {
            $query->whereHas('student', fn ($student) => $student->where('class_id', $filters['class_id']));
        }
        if (isset($filters['academic_year_id'])) {
            $query->where(fn ($scope) => $scope
                ->whereHas('studentFee', fn ($fee) => $fee->where('academic_year_id', $filters['academic_year_id']))
                ->orWhereHas('student', fn ($student) => $student->where('academic_year_id', $filters['academic_year_id'])));
        }
        if (isset($filters['status'])) {
            $query->whereHas('studentFee', fn ($fee) => $fee->where('status', $filters['status']));
        }
        if ($book === 'cash') $query->where('payment_method', 'cash');
        if ($book === 'bank') $query->whereIn('payment_method', ['bank', 'mobile_banking']);
        if ($admissionOnly) {
            $query->where(function ($payment) {
                $payment->whereHas('studentFee.feeType', fn ($feeType) => $feeType->whereRaw('LOWER(name) LIKE ?', ['%admission%']))
                    ->orWhereHas('studentFee.feeItem', fn ($feeItem) => $feeItem->whereRaw('LOWER(name) LIKE ?', ['%admission%']))
                    ->orWhereHas('studentFee.feeItem.category', fn ($category) => $category->whereRaw('LOWER(name) LIKE ?', ['%admission%']));
            });
        }

        return $query;
    }

    private function paymentResponse($payments)
    {
        return response()->json([
            'payment_count' => $payments->count(),
            'total_collected' => $this->money->formatCents($this->money->sum($payments->pluck('amount'))),
            'payments' => $payments,
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