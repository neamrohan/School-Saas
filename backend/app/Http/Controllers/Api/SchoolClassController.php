<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolClassController extends Controller
{
    public function index(Request $request)
    {
        $classes = SchoolClass::where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'classes' => $classes,
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
                Rule::unique('school_classes', 'name')
                    ->where('school_id', $admin->school_id),
            ],
            'code' => ['nullable', 'string', 'max:50'],
            'numeric_order' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schoolClass = SchoolClass::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Class created successfully.',
            'class' => $schoolClass,
        ], 201);
    }

    public function show(Request $request, SchoolClass $schoolClass)
    {
        $this->checkSchoolAccess($request, $schoolClass);

        return response()->json([
            'class' => $schoolClass,
        ]);
    }

    public function update(Request $request, SchoolClass $schoolClass)
    {
        $this->checkSchoolAccess($request, $schoolClass);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('school_classes', 'name')
                    ->where('school_id', $request->user()->school_id)
                    ->ignore($schoolClass->id),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'numeric_order' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schoolClass->update($validated);

        return response()->json([
            'message' => 'Class updated successfully.',
            'class' => $schoolClass->fresh(),
        ]);
    }

    public function destroy(Request $request, SchoolClass $schoolClass)
    {
        $this->checkSchoolAccess($request, $schoolClass);

        $schoolClass->delete();

        return response()->json([
            'message' => 'Class deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(
        Request $request,
        SchoolClass $schoolClass
    ): void {
        if ($schoolClass->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}