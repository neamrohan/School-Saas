<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
        ]);

        $query = Exam::where('school_id', $request->user()->school_id);

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $filters['is_active']);
        }

        if (array_key_exists('start_date', $filters)) {
            $query->whereDate('start_date', '>=', $filters['start_date']);
        }

        if (array_key_exists('end_date', $filters)) {
            $query->whereDate('end_date', '<=', $filters['end_date']);
        }

        return response()->json([
            'exams' => $query->with(['academicYear', 'class', 'examSubjects.subject'])->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate($this->examRules($admin->school_id));
        $this->validateAcademicContext($request, $validated);
        $this->validateDateRange($validated);

        $exam = Exam::create([
            ...$validated,
            'school_id' => $admin->school_id,
        ]);

        return response()->json([
            'message' => 'Exam created successfully.',
            'exam' => $exam,
        ], 201);
    }

    public function show(Request $request, Exam $exam)
    {
        $this->checkSchoolAccess($request, $exam);

        return response()->json([
            'exam' => $exam,
        ]);
    }

    public function update(Request $request, Exam $exam)
    {
        $this->checkSchoolAccess($request, $exam);

        $validated = $request->validate($this->examRules(
            $request->user()->school_id,
            $exam
        ));
        $this->validateAcademicContext($request, $validated, $exam);
        $this->validateDateRange($validated, $exam);

        $exam->update($validated);

        return response()->json([
            'message' => 'Exam updated successfully.',
            'exam' => $exam->fresh(),
        ]);
    }

    public function destroy(Request $request, Exam $exam)
    {
        $this->checkSchoolAccess($request, $exam);

        $exam->delete();

        return response()->json([
            'message' => 'Exam deleted successfully.',
        ]);
    }

    private function examRules(int $schoolId, ?Exam $exam = null): array
    {
        $uniqueName = Rule::unique('exams', 'name')
            ->where('school_id', $schoolId);

        if ($exam) {
            $uniqueName->ignore($exam->id);
            $name = ['sometimes', 'required', 'string', 'max:255', $uniqueName];
        } else {
            $name = ['required', 'string', 'max:255', $uniqueName];
        }

        return [
            'name' => $name,
            'academic_year_id' => [$exam ? 'sometimes' : 'required', 'integer', 'exists:academic_years,id'],
            'class_id' => [$exam ? 'sometimes' : 'required', 'integer', 'exists:school_classes,id'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function validateDateRange(array $validated, ?Exam $exam = null): void
    {
        $startDate = array_key_exists('start_date', $validated)
            ? $validated['start_date']
            : $exam?->start_date?->toDateString();
        $endDate = array_key_exists('end_date', $validated)
            ? $validated['end_date']
            : $exam?->end_date?->toDateString();

        if ($startDate && $endDate && $endDate < $startDate) {
            abort(response()->json([
                'message' => 'End date cannot be earlier than start date.',
            ], 422));
        }
    }

    private function checkSchoolAccess(Request $request, Exam $exam): void
    {
        if ($exam->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }

    }

    private function validateAcademicContext(Request $request, array $validated, ?Exam $exam = null): void
    {
        $schoolId = $request->user()->school_id;
        $yearId = $validated['academic_year_id'] ?? $exam?->academic_year_id;
        $classId = $validated['class_id'] ?? $exam?->class_id;

        if (! AcademicYear::where('school_id', $schoolId)->whereKey($yearId)->exists()
            || ! SchoolClass::where('school_id', $schoolId)->whereKey($classId)->exists()) {
            abort(response()->json(['message' => 'Academic year and class must belong to your school.'], 422));
        }
    }
}