<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\StudentWaiver;
use App\Models\WaiverType;
use App\Services\MoneyCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WaiverController extends Controller
{
    public function __construct(private readonly MoneyCalculator $money) {}

    public function types(Request $request): JsonResponse
    {
        $types = WaiverType::where('school_id', $request->user()->school_id)
            ->withCount('waivers')->orderBy('name')->paginate(25);

        return response()->json(['waiver_types' => $types]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('waiver_types')->where('school_id', $schoolId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'calculation_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
        ]);
        $this->validateTypeValue($data['calculation_type'], $data['value']);
        $type = WaiverType::create([...$data, 'school_id' => $schoolId, 'is_active' => true]);

        return response()->json(['message' => 'Waiver type created.', 'waiver_type' => $type], 201);
    }

    public function updateType(Request $request, WaiverType $waiverType): JsonResponse
    {
        $this->checkSchool($request, $waiverType->school_id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('waiver_types')->where('school_id', $waiverType->school_id)->ignore($waiverType->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'calculation_type' => ['sometimes', 'required', Rule::in(['percentage', 'fixed'])],
            'value' => ['sometimes', 'required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validateTypeValue($data['calculation_type'] ?? $waiverType->calculation_type, $data['value'] ?? $waiverType->value);
        $waiverType->update($data);

        return response()->json(['message' => 'Waiver type updated.', 'waiver_type' => $waiverType->fresh()]);
    }

    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'revoked'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = StudentWaiver::with(['student.user', 'studentFee.feeItem', 'studentFee.feeType', 'waiverType', 'assignedBy', 'revokedBy'])
            ->where('school_id', $request->user()->school_id)
            ->when(isset($filters['student_id']), fn ($q) => $q->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->latest('assigned_at');

        return response()->json(['waivers' => $query->paginate(25)]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'student_fee_id' => ['required', 'integer'],
            'waiver_type_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $schoolId = $request->user()->school_id;

        $waiver = DB::transaction(function () use ($request, $data, $schoolId): StudentWaiver {
            $student = Student::where('school_id', $schoolId)->findOrFail($data['student_id']);
            $fee = StudentFee::where('school_id', $schoolId)->where('student_id', $student->id)
                ->lockForUpdate()->findOrFail($data['student_fee_id']);
            $type = WaiverType::where('school_id', $schoolId)->where('is_active', true)
                ->findOrFail($data['waiver_type_id']);

            $existing = StudentWaiver::where('student_fee_id', $fee->id)->where('waiver_type_id', $type->id)
                ->where('status', 'active')->exists();
            abort_if($existing, 422, 'This waiver type is already active for the selected fee.');

            $feeCents = $this->money->toCents($fee->amount);
            $paymentsCents = $this->money->sum($fee->payments()->pluck('amount'));
            $activeWaiverCents = $this->money->sum(
                StudentWaiver::where('student_fee_id', $fee->id)->where('status', 'active')->pluck('amount')
            );
            $remainingCents = $feeCents - $paymentsCents - $activeWaiverCents;
            abort_if($remainingCents <= 0, 422, 'This fee has no remaining balance eligible for a waiver.');

            $valueCents = $this->money->toCents($type->value);
            $waiverCents = $type->calculation_type === 'percentage'
                ? intdiv(($feeCents * $valueCents) + 5000, 10000)
                : $valueCents;
            abort_if($waiverCents > $remainingCents, 422, 'The waiver exceeds the remaining fee balance.');

            $waiver = StudentWaiver::create([
                'school_id' => $schoolId,
                'student_id' => $student->id,
                'student_fee_id' => $fee->id,
                'waiver_type_id' => $type->id,
                'amount' => $this->money->formatCents($waiverCents),
                'status' => 'active',
                'reason' => $data['reason'] ?? null,
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
            ]);
            $this->refreshFeeStatus($fee);

            return $waiver;
        });

        return response()->json([
            'message' => 'Waiver applied successfully.',
            'waiver' => $waiver->load(['student.user', 'studentFee', 'waiverType', 'assignedBy']),
        ], 201);
    }

    public function revoke(Request $request, StudentWaiver $waiver): JsonResponse
    {
        $this->checkSchool($request, $waiver->school_id);
        abort_unless($waiver->status === 'active', 409, 'Only active waivers can be revoked.');

        DB::transaction(function () use ($request, $waiver): void {
            $locked = StudentWaiver::query()->lockForUpdate()->findOrFail($waiver->id);
            abort_unless($locked->status === 'active', 409, 'This waiver has already been revoked.');
            $locked->update([
                'status' => 'revoked',
                'revoked_by' => $request->user()->id,
                'revoked_at' => now(),
            ]);
            $this->refreshFeeStatus(StudentFee::findOrFail($locked->student_fee_id));
        });

        return response()->json(['message' => 'Waiver revoked and retained in history.']);
    }

    private function refreshFeeStatus(StudentFee $fee): void
    {
        $paid = $this->money->sum($fee->payments()->pluck('amount'));
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

    private function validateTypeValue(string $type, string $value): void
    {
        $cents = $this->money->toCents($value);
        abort_if($type === 'percentage' && $cents > 10000, 422, 'Percentage waiver cannot exceed 100%.');
    }

    private function checkSchool(Request $request, int $schoolId): void
    {
        abort_unless($schoolId === $request->user()->school_id, 404);
    }
}
