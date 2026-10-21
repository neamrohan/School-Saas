<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\StudentWaiver;
use App\Services\MoneyCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $payment = DB::transaction(function () use ($data, $request) {
            $fee = StudentFee::with('payments')->where('school_id', $request->user()->school_id)
                ->lockForUpdate()->find($data['student_fee_id']);
            $student = Student::where('school_id', $request->user()->school_id)->find($data['student_id']);
            $this->checkRecords($request, $fee, $student);
            $this->ensureWithinBalance($fee, $data['amount']);
            $data['transaction_reference'] = $data['transaction_reference'] ?? $this->receiptReference($request);
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
        $payment = DB::transaction(function () use ($request, $payment, $data, $feeId, $studentId) {
            $lockedPayment = Payment::where('school_id', $request->user()->school_id)
                ->lockForUpdate()->findOrFail($payment->id);
            $previousFeeId = $lockedPayment->student_fee_id;
            $fees = StudentFee::where('school_id', $request->user()->school_id)
                ->whereIn('id', collect([$previousFeeId, $feeId])->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $fee = $fees->get($feeId);
            $student = Student::where('school_id', $request->user()->school_id)->find($studentId);
            $this->checkRecords($request, $fee, $student);
            $fee->loadMissing('payments');
            $paid = $this->money->sum($fee->payments->where('id', '!=', $lockedPayment->id)->pluck('amount'));
            $waived = $this->money->sum(
                StudentWaiver::where('student_fee_id', $fee->id)->where('status', 'active')->pluck('amount')
            );
            $remaining = $this->money->toCents($fee->amount) - $paid - $waived;
            if ($this->money->toCents($data['amount'] ?? $lockedPayment->amount) > $remaining) {
                abort(response()->json(['message' => 'Payment exceeds the remaining due amount.'], 422));
            }

            $lockedPayment->update($data);
            $this->refreshStatus($fee->fresh('payments'));
            if ($previousFeeId !== $fee->id && $fees->has($previousFeeId)) {
                $this->refreshStatus($fees->get($previousFeeId)->fresh('payments'));
            }

            return $lockedPayment;
        });

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
        $fees = $student->studentFees()->with(['feeType', 'feeItem', 'payments', 'waivers'])->get();
        $total = $this->money->sum($fees->pluck('amount'));
        $paid = $this->money->sum($fees->flatMap->payments->pluck('amount'));
        $waived = $this->money->sum($fees->flatMap->waivers->where('status', 'active')->pluck('amount'));
        return response()->json([
            'student' => $student->load('user'),
            'total_fees' => $this->money->formatCents($total),
            'total_paid' => $this->money->formatCents($paid),
            'total_waived' => $this->money->formatCents($waived),
            'total_due' => $this->money->formatCents(max(0, $total - $paid - $waived)),
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
        $waived = $this->money->sum(
            StudentWaiver::where('student_fee_id', $fee->id)->where('status', 'active')->pluck('amount')
        );
        if ($this->money->toCents($amount) > $this->money->toCents($fee->amount) - $paid - $waived) abort(response()->json(['message' => 'Payment exceeds the remaining due amount.'], 422));
    }

    private function refreshStatus(StudentFee $fee): void
    {
        $paid = $this->money->sum($fee->payments->pluck('amount'));
        $waived = $this->money->sum(
            StudentWaiver::where('student_fee_id', $fee->id)->where('status', 'active')->pluck('amount')
        );
        $amount = $this->money->toCents($fee->amount);
        $fee->update([
            'status' => $paid + $waived >= $amount
                ? ($paid === 0 ? 'waived' : 'paid')
                : ($paid + $waived > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    private function receiptReference(Request $request): string
    {
        do {
            $reference = 'RCP-'.$request->user()->school_id.'-'.Str::upper(Str::random(12));
        } while (Payment::where('school_id', $request->user()->school_id)->where('transaction_reference', $reference)->exists());

        return $reference;
    }

    private function checkSchool(Request $request, int $schoolId): void
    {
        if ($schoolId !== $request->user()->school_id) abort(response()->json(['message' => 'Unauthorized school access.'], 403));
    }
}