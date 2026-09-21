<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeTypeController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['fee_types' => FeeType::where('school_id', $request->user()->school_id)->latest()->get()]);
    }

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('fee_types', 'name')->where('school_id', $schoolId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $feeType = FeeType::create([...$validated, 'school_id' => $schoolId]);

        return response()->json(['message' => 'Fee type created successfully.', 'fee_type' => $feeType], 201);
    }

    public function show(Request $request, FeeType $feeType)
    {
        $this->checkSchool($request, $feeType->school_id);
        return response()->json(['fee_type' => $feeType]);
    }

    public function update(Request $request, FeeType $feeType)
    {
        $this->checkSchool($request, $feeType->school_id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('fee_types', 'name')->where('school_id', $request->user()->school_id)->ignore($feeType->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $feeType->update($validated);
        return response()->json(['message' => 'Fee type updated successfully.', 'fee_type' => $feeType->fresh()]);
    }

    public function destroy(Request $request, FeeType $feeType)
    {
        $this->checkSchool($request, $feeType->school_id);
        $feeType->delete();
        return response()->json(['message' => 'Fee type deleted successfully.']);
    }

    private function checkSchool(Request $request, int $schoolId): void
    {
        if ($schoolId !== $request->user()->school_id) {
            abort(response()->json(['message' => 'Unauthorized school access.'], 403));
        }
    }
}