<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    public function index(Request $request)
    {
        $sections = Section::whereHas('schoolClass', function ($query) use ($request) {
            $query->where('school_id', $request->user()->school_id);
        })
            ->with('schoolClass')
            ->latest()
            ->get();

        return response()->json([
            'sections' => $sections,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'school_class_id' => [
                'required',
                Rule::exists('school_classes', 'id')
                    ->where('school_id', $admin->school_id),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sections', 'name')
                    ->where('school_class_id', $request->input('school_class_id')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $section = Section::create($validated);

        return response()->json([
            'message' => 'Section created successfully.',
            'section' => $section->load('schoolClass'),
        ], 201);
    }

    public function show(Request $request, Section $section)
    {
        $this->checkSchoolAccess($request, $section);

        return response()->json([
            'section' => $section->load('schoolClass'),
        ]);
    }

    public function update(Request $request, Section $section)
    {
        $this->checkSchoolAccess($request, $section);

        $validated = $request->validate([
            'school_class_id' => [
                'sometimes',
                'required',
                Rule::exists('school_classes', 'id')
                    ->where('school_id', $request->user()->school_id),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sections', 'name')
                    ->where('school_class_id', $request->input('school_class_id', $section->school_class_id))
                    ->ignore($section->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $section->update($validated);

        return response()->json([
            'message' => 'Section updated successfully.',
            'section' => $section->fresh()->load('schoolClass'),
        ]);
    }

    public function destroy(Request $request, Section $section)
    {
        $this->checkSchoolAccess($request, $section);

        $section->delete();

        return response()->json([
            'message' => 'Section deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, Section $section): void
    {
        if ($section->schoolClass->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}