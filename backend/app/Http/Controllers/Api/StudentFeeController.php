<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentFeeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'fee_type_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'due_date' => ['sometimes', 'date'],
        ]);
        $query = StudentFee::with(['student.user', 'feeType', 'payments'])->where('school_id', $request->user()->school_id);
        foreach (['student_id', 'fee_type_id', 'status', 'due_date'] as $filter) {
            if (array_key_exists($filter, $filters)) $query->where($filter, $filters[$filter]);
        }
        return response()->json(['student_fees' => $query->latest()->get()]);
    }

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'fee_type_id' => ['required', 'integer', 'exists:fee_types,id'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ]);
        $this->validateRecords($schoolId, $validated['student_id'], $validated['fee_type_id']);
        $fee = StudentFee::create([...$validated, 'school_id' => $schoolId, 'status' => $validated['status'] ?? 'unpaid']);
        return response()->json(['message' => 'Student fee created successfully.', 'student_fee' => $fee->load(['student.user', 'feeType', 'payments'])], 201);
    }

    public function show(Request $request, StudentFee $studentFee)
    {
        $this->checkSchool($request, $studentFee->school_id);
        return response()->json(['student_fee' => $studentFee->load(['student.user', 'feeType', 'payments'])]);
    }

    public function update(Request $request, StudentFee $studentFee)
    {
        $this->checkSchool($request, $studentFee->school_id);
        $validated = $request->validate([
            'student_id' => ['sometimes', 'required', 'integer', 'exists:students,id'],
            'fee_type_id' => ['sometimes', 'required', 'integer', 'exists:fee_types,id'],
            'amount' => ['sometimes', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in($this->statuses())],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ]);
        $this->validateRecords($request->user()->school_id, $validated['student_id'] ?? $studentFee->student_id, $validated['fee_type_id'] ?? $studentFee->fee_type_id);
        $studentFee->update($validated);
        return response()->json(['message' => 'Student fee updated successfully.', 'student_fee' => $studentFee->fresh()->load(['student.user', 'feeType', 'payments'])]);
    }

    public function destroy(Request $request, StudentFee $studentFee)
    {
        $this->checkSchool($request, $studentFee->school_id);
        $studentFee->delete();
        return response()->json(['message' => 'Student fee deleted successfully.']);
    }

    private function statuses(): array { return ['unpaid', 'partial', 'paid', 'waived']; }

    private function validateRecords(int $schoolId, int $studentId, int $feeTypeId): void
    {
        $student = Student::find($studentId);
        $feeType = FeeType::find($feeTypeId);
        if ($student?->school_id !== $schoolId || $feeType?->school_id !== $schoolId) {
            abort(response()->json(['message' => 'Unauthorized school access.'], 403));
        }
    }

    private function checkSchool(Request $request, int $schoolId): void
    {
        if ($schoolId !== $request->user()->school_id) abort(response()->json(['message' => 'Unauthorized school access.'], 403));
    }
}