<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeItem;
use App\Models\FeePricing;
use App\Models\MonthlyFeeSetup;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeeManagementController extends Controller
{
    public function categories(Request $request)
    {
        return response()->json(['categories' => FeeCategory::where('school_id', $this->school($request))->withCount('items')->latest()->get()]);
    }

    public function categoryStore(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('fee_categories')->where('school_id', $school)], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']]);
        return response()->json(['category' => FeeCategory::create([...$data, 'school_id' => $school])], 201);
    }

    public function categoryUpdate(Request $request, FeeCategory $category)
    {
        $this->check($request, $category->school_id);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('fee_categories')->where('school_id', $category->school_id)->ignore($category->id)], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']]);
        $category->update($data);
        return response()->json(['category' => $category->fresh()]);
    }

    public function categoryDestroy(Request $request, FeeCategory $category)
    {
        $this->check($request, $category->school_id); $category->delete();
        return response()->json(['message' => 'Category deleted successfully.']);
    }

    public function items(Request $request)
    {
        return response()->json(['items' => FeeItem::where('school_id', $this->school($request))->with('category')->latest()->get()]);
    }

    public function itemStore(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate(['category_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255', Rule::unique('fee_items')->where('school_id', $school)], 'code' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']]);
        $this->sameSchool($school, FeeCategory::find($data['category_id']));
        return response()->json(['item' => FeeItem::create([...$data, 'school_id' => $school])->load('category')], 201);
    }

    public function itemUpdate(Request $request, FeeItem $item)
    {
        $this->check($request, $item->school_id);
        $data = $request->validate(['category_id' => ['sometimes', 'required', 'integer'], 'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('fee_items')->where('school_id', $item->school_id)->ignore($item->id)], 'code' => ['nullable', 'string', 'max:50'], 'description' => ['nullable', 'string'], 'is_active' => ['boolean']]);
        if (isset($data['category_id'])) $this->sameSchool($item->school_id, FeeCategory::find($data['category_id']));
        $item->update($data);
        return response()->json(['item' => $item->fresh()->load('category')]);
    }

    public function itemDestroy(Request $request, FeeItem $item)
    {
        $this->check($request, $item->school_id); $item->delete();
        return response()->json(['message' => 'Item deleted successfully.']);
    }

    public function pricings(Request $request)
    {
        return response()->json(['pricings' => FeePricing::where('school_id', $this->school($request))->with(['item', 'academicYear', 'class'])->latest()->get()]);
    }

    public function pricingStore(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate(['fee_item_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'class_id' => ['nullable', 'integer'], 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'is_active' => ['boolean']]);
        $this->validateContext($school, $data);
        $exists = FeePricing::where($this->configuration($data))->exists();
        if ($exists) abort(response()->json(['message' => 'This pricing configuration already exists.'], 422));
        return response()->json(['pricing' => FeePricing::create([...$data, 'school_id' => $school])->load(['item', 'academicYear', 'class'])], 201);
    }

    public function monthly(Request $request)
    {
        return response()->json(['monthly_setups' => MonthlyFeeSetup::where('school_id', $this->school($request))->with(['item', 'academicYear', 'class'])->latest()->get()]);
    }

    public function monthlyStore(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate(['fee_item_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'class_id' => ['nullable', 'integer'], 'month' => ['required', 'integer', 'between:1,12'], 'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'is_active' => ['boolean']]);
        $this->validateContext($school, $data);
        if (MonthlyFeeSetup::where($this->configuration($data))->where('month', $data['month'])->exists()) abort(response()->json(['message' => 'This monthly configuration already exists.'], 422));
        return response()->json(['monthly_setup' => MonthlyFeeSetup::create([...$data, 'school_id' => $school])->load(['item', 'academicYear', 'class'])], 201);
    }

    public function generateDue(Request $request)
    {
        $data = $request->validate(['academic_year_id' => ['required', 'integer'], 'class_id' => ['required', 'integer'], 'section_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'], 'fee_item_id' => ['required', 'integer'], 'month' => ['required', 'integer', 'between:1,12'], 'due_date' => ['nullable', 'date']]);
        $school = $this->school($request); $this->validateContext($school, $data);
        $students = Student::where('school_id', $school)->where('class_id', $data['class_id'])->when($data['section_id'] ?? null, fn ($q, $id) => $q->where('section_id', $id))->when($data['student_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();
        if ($students->isEmpty()) abort(response()->json(['message' => 'No matching students found.'], 422));
        $setup = MonthlyFeeSetup::where('school_id', $school)->where($this->configuration($data))->where('month', $data['month'])->where('is_active', true)->first()
            ?? FeePricing::where('school_id', $school)->where($this->configuration($data))->where('is_active', true)->first();
        if (!$setup) abort(response()->json(['message' => 'No active pricing or monthly setup exists.'], 422));
        $amount = $setup->amount; $created = 0; $skipped = 0;
        DB::transaction(function () use ($students, $data, $school, $amount, &$created, &$skipped) {
            foreach ($students as $student) {
                $fee = StudentFee::firstOrCreate(
                    ['school_id' => $school, 'student_id' => $student->id, 'fee_item_id' => $data['fee_item_id'], 'academic_year_id' => $data['academic_year_id'], 'month' => $data['month']],
                    ['class_id' => $student->class_id, 'section_id' => $student->section_id, 'amount' => $amount, 'due_date' => $data['due_date'] ?? null, 'status' => 'unpaid']
                );
                $fee->wasRecentlyCreated ? $created++ : $skipped++;
            }
        });
        return response()->json(['message' => 'Due generation completed.', 'generated' => $created, 'already_existed' => $skipped]);
    }

    private function validateContext(int $school, array $data): void
    {
        $this->sameSchool($school, FeeItem::find($data['fee_item_id']));
        $this->sameSchool($school, AcademicYear::find($data['academic_year_id']));
        if (!empty($data['class_id'])) $this->sameSchool($school, SchoolClass::find($data['class_id']));
        if (!empty($data['section_id'])) $this->sameSchool($school, Section::whereKey($data['section_id'])->whereHas('schoolClass', fn ($q) => $q->where('school_id', $school))->first());
    }

    private function configuration(array $data): array
    {
        return ['fee_item_id' => $data['fee_item_id'], 'academic_year_id' => $data['academic_year_id'], 'class_id' => $data['class_id'] ?? null];
    }

    private function school(Request $request): int { return (int) $request->user()->school_id; }
    private function sameSchool(int $school, ?object $record): void { if (!$record || ($record->school_id ?? null) !== $school) abort(response()->json(['message' => 'Referenced record does not belong to your school.'], 422)); }
    private function check(Request $request, int $school): void { if ($school !== $this->school($request)) abort(response()->json(['message' => 'Unauthorized school access.'], 403)); }
}
