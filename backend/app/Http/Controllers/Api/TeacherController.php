<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $teachers = Teacher::with('user')
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'teachers' => $teachers,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
        ]);

        $user = User::where('id', $validated['user_id'])
            ->where('school_id', $admin->school_id)
            ->where('role', 'teacher')
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Teacher user not found in your school.',
            ], 422);
        }

        $teacher = Teacher::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Teacher profile created successfully.',
            'teacher' => $teacher->load('user'),
        ], 201);
    }

    public function show(Request $request, Teacher $teacher)
    {
        $this->checkSchoolAccess($request, $teacher);

        return response()->json([
            'teacher' => $teacher->load('user'),
        ]);
    }

    public function update(Request $request, Teacher $teacher)
    {
        $this->checkSchoolAccess($request, $teacher);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
        ]);

        $teacher->update($validated);

        return response()->json([
            'message' => 'Teacher profile updated successfully.',
            'teacher' => $teacher->fresh()->load('user'),
        ]);
    }

    public function destroy(Request $request, Teacher $teacher)
    {
        $this->checkSchoolAccess($request, $teacher);

        $teacher->delete();

        return response()->json([
            'message' => 'Teacher profile deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, Teacher $teacher): void
    {
        if ($teacher->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}
