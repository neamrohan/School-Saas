<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $subjects = Subject::where('school_id', $request->user()->school_id)
            ->latest()
            ->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('subjects', 'code')
                    ->where('school_id', $admin->school_id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $subject = Subject::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Subject created successfully.',
            'subject' => $subject,
        ], 201);
    }

    public function show(Request $request, Subject $subject)
    {
        $this->checkSchoolAccess($request, $subject);

        return response()->json([
            'subject' => $subject,
        ]);
    }

    public function update(Request $request, Subject $subject)
    {
        $this->checkSchoolAccess($request, $subject);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('subjects', 'code')
                    ->where('school_id', $request->user()->school_id)
                    ->ignore($subject->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $subject->update($validated);

        return response()->json([
            'message' => 'Subject updated successfully.',
            'subject' => $subject->fresh(),
        ]);
    }

    public function destroy(Request $request, Subject $subject)
    {
        $this->checkSchoolAccess($request, $subject);

        $subject->delete();

        return response()->json([
            'message' => 'Subject deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, Subject $subject): void
    {
        if ($subject->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}