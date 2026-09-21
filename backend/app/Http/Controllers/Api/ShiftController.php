<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $shifts = Shift::where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'shifts' => $shifts,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('shifts', 'name')->where('school_id', $admin->school_id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $shift = Shift::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Shift created successfully.',
            'shift' => $shift,
        ], 201);
    }

    public function show(Request $request, Shift $shift)
    {
        $this->checkSchoolAccess($request, $shift);

        return response()->json([
            'shift' => $shift,
        ]);
    }

    public function update(Request $request, Shift $shift)
    {
        $this->checkSchoolAccess($request, $shift);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('shifts', 'name')
                    ->where('school_id', $request->user()->school_id)
                    ->ignore($shift->id),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $shift->update($validated);

        return response()->json([
            'message' => 'Shift updated successfully.',
            'shift' => $shift->fresh(),
        ]);
    }

    public function destroy(Request $request, Shift $shift)
    {
        $this->checkSchoolAccess($request, $shift);

        $shift->delete();

        return response()->json([
            'message' => 'Shift deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, Shift $shift): void
    {
        if ($shift->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}
