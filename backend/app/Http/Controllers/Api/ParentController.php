<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();

        $parents = User::with(['school', 'children.user'])
            ->where('school_id', $admin->school_id)
            ->where('role', 'parent')
            ->latest()
            ->get();

        return response()->json([
            'parents' => $parents,
        ]);
    }

    public function show(Request $request, User $parent)
    {
        $this->checkSchoolAccess($request, $parent);

        if ($parent->role !== 'parent') {
            return response()->json([
                'message' => 'User is not a parent.',
            ], 422);
        }

        return response()->json([
            'parent' => $parent->load([
                'school',
                'children.user',
            ]),
        ]);
    }

    public function attachStudent(Request $request, User $parent)
    {
        $this->checkSchoolAccess($request, $parent);

        if ($parent->role !== 'parent') {
            return response()->json([
                'message' => 'User is not a parent.',
            ], 422);
        }

        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'relationship' => ['nullable', 'string', 'max:50'],
        ]);

        $student = \App\Models\Student::where('id', $validated['student_id'])
            ->where('school_id', $request->user()->school_id)
            ->first();

        if (! $student) {
            return response()->json([
                'message' => 'Student not found in your school.',
            ], 422);
        }

        if ($parent->children()->where('students.id', $student->id)->exists()) {
            return response()->json([
                'message' => 'Student is already assigned to this parent.',
            ], 422);
        }

        $parent->children()->attach($student->id, [
            'relationship' => $validated['relationship'] ?? null,
        ]);

        return response()->json([
            'message' => 'Student assigned to parent successfully.',
            'parent' => $parent->load([
                'school',
                'children.user',
            ]),
        ], 201);
    }

    public function detachStudent(Request $request, User $parent, \App\Models\Student $student)
    {
        $this->checkSchoolAccess($request, $parent);

        if ($parent->role !== 'parent') {
            return response()->json([
                'message' => 'User is not a parent.',
            ], 422);
        }

        if ($student->school_id !== $request->user()->school_id) {
            return response()->json([
                'message' => 'Student not found in your school.',
            ], 422);
        }

        $parent->children()->detach($student->id);

        return response()->json([
            'message' => 'Student removed from parent successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, User $parent): void
    {
        if ($parent->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}