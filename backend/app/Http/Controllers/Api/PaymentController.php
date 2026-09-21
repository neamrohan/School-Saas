<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentFee;
use App\Services\MoneyCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly MoneyCalculator $money) {}

    public function index(Request $request)
    {
        return response()->json(['payments' => Payment::with(['student.user', 'studentFee.feeType'])->where('school_id', $request->user()->school_id)->latest()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $fee = StudentFee::with('payments')->find($data['student_fee_id']);
        $student = Student::find($data['student_id']);
        $this->checkRecords($request, $fee, $student);
        $this->ensureWithinBalance($fee, $data['amount']);
        $payment = DB::transaction(function () use ($data, $request, $fee) {
            $payment = Payment::create([...$data, 'school_id' => $request->user()->school_id]);
            $this->refreshStatus($fee->fresh('payments'));
            return $payment;
        });
        return response()->json(['message' => 'Payment recorded successfully.', 'payment' => $payment->load(['student.user', 'studentFee.feeType'])], 201);
    }

    public function show(Request $request, Payment $payment)
    {
        $this->checkSchool($request, $payment->school_id);
        return response()->json(['payment' => $payment->load(['student.user', 'studentFee.feeType'])]);
    }

    public function update(Request $request, Payment $payment)
    {
        $this->checkSchool($request, $payment->school_id);
        $data = $request->validate($this->rules(true));
        $feeId = $data['student_fee_id'] ?? $payment->student_fee_id;
        $studentId = $data['student_id'] ?? $payment->student_id;
        $fee = StudentFee::with('payments')->find($feeId);
        $student = Student::find($studentId);
        $this->checkRecords($request, $fee, $student);
        $currentTotal = $this->money->sum($fee->payments->where('id', '!=', $payment->id)->pluck('amount'));
        $remaining = $this->money->toCents($fee->amount) - $currentTotal;
        if ($this->money->toCents($data['amount'] ?? $payment->amount) > $remaining) abort(response()->json(['message' => 'Payment exceeds the remaining due amount.'], 422));
        $payment->update($data);
        $this->refreshStatus($fee->fresh('payments'));
        return response()->json(['message' => 'Payment updated successfully.', 'payment' => $payment->fresh()->load(['student.user', 'studentFee.feeType'])]);
    }

    public function destroy(Request $request, Payment $payment)
    {
        $this->checkSchool($request, $payment->school_id);
        $fee = $payment->studentFee;
        $payment->delete();
        $this->refreshStatus($fee->fresh('payments'));
        return response()->json(['message' => 'Payment deleted successfully.']);
    }

    public function summary(Request $request, Student $student)
    {
        $this->checkSchool($request, $student->school_id);
        $fees = $student->studentFees()->with(['feeType', 'payments'])->get();
        $total = $this->money->sum($fees->pluck('amount'));
        $paid = $this->money->sum($fees->flatMap->payments->pluck('amount'));
        return response()->json([
            'student' => $student->load('user'),
            'total_fees' => $this->money->formatCents($total),
            'total_paid' => $this->money->formatCents($paid),
            'total_due' => $this->money->formatCents($total - $paid),
            'breakdown' => $fees->groupBy('status')->map->count(),
            'fees' => $fees,
            'payments' => $fees->flatMap->payments->values(),
        ]);
    }

    private function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';
        return [
            'student_fee_id' => [$presence, 'integer', 'exists:student_fees,id'],
            'student_id' => [$presence, 'integer', 'exists:students,id'],
            'amount' => [$presence, 'regex:/^\d+(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
            'payment_date' => [$presence, 'date'],
            'payment_method' => [$presence, Rule::in(['cash', 'bank', 'mobile_banking', 'other'])],
            'transaction_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    private function checkRecords(Request $request, ?StudentFee $fee, ?Student $student): void
    {
        if (! $fee || ! $student || $fee->school_id !== $request->user()->school_id || $student->school_id !== $request->user()->school_id || $fee->student_id !== $student->id) abort(response()->json(['message' => 'Unauthorized school access.'], 403));
    }

    private function ensureWithinBalance(StudentFee $fee, string $amount): void
    {
        $paid = $this->money->sum($fee->payments->pluck('amount'));
        if ($this->money->toCents($amount) > $this->money->toCents($fee->amount) - $paid) abort(response()->json(['message' => 'Payment exceeds the remaining due amount.'], 422));
    }

    private function refreshStatus(StudentFee $fee): void
    {
        $paid = $this->money->sum($fee->payments->pluck('amount'));
        $amount = $this->money->toCents($fee->amount);
        $fee->update(['status' => $paid === 0 ? 'unpaid' : ($paid >= $amount ? 'paid' : 'partial')]);
    }

    private function checkSchool(Request $request, int $schoolId): void
    {
        if ($schoolId !== $request->user()->school_id) abort(response()->json(['message' => 'Unauthorized school access.'], 403));
    }
}