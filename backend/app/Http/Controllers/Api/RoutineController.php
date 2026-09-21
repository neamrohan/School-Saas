<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Routine;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\AcademicYear;
use App\Models\Shift;
use App\Models\RoutinePeriod;
use App\Models\RoutineRoom;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutineController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'class_id' => ['sometimes', 'integer'],
            'section_id' => ['sometimes', 'integer'],
            'teacher_id' => ['sometimes', 'integer'],
            'subject_id' => ['sometimes', 'integer'],
            'day_of_week' => ['sometimes', Rule::in($this->days())],
            'academic_year_id' => ['sometimes', 'integer'],
            'shift_id' => ['sometimes', 'integer'],
            'period_id' => ['sometimes', 'integer'],
            'room_id' => ['sometimes', 'integer'],
        ]);

        $query = Routine::with([
            'class',
            'section',
            'subject',
            'teacher.user',
            'academicYear',
            'shift',
            'period',
            'roomEntity',
        ])->where('school_id', $request->user()->school_id);

        foreach (['class_id', 'section_id', 'teacher_id', 'subject_id', 'day_of_week'] as $filter) {
            if (array_key_exists($filter, $filters)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return response()->json([
            'routines' => $query->orderBy('day_of_week')->orderBy('start_time')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $this->validateRoutineRecords($request, $validated);
        $this->ensureNoConflicts($request, $validated);

        $routine = Routine::create([
            ...$validated,
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => 'Routine created successfully.',
            'routine' => $routine->load([
                'class',
                'section',
                'subject',
                'teacher.user',
            ]),
        ], 201);
    }

    public function show(Request $request, Routine $routine)
    {
        $this->checkSchoolAccess($request, $routine);

        return response()->json([
            'routine' => $routine->load([
                'class',
                'section',
                'subject',
                'teacher.user',
            ]),
        ]);
    }

    public function update(Request $request, Routine $routine)
    {
        $this->checkSchoolAccess($request, $routine);

        $validated = $request->validate($this->rules(true));
        $effective = [
            'class_id' => $validated['class_id'] ?? $routine->class_id,
            'section_id' => $validated['section_id'] ?? $routine->section_id,
            'subject_id' => $validated['subject_id'] ?? $routine->subject_id,
            'teacher_id' => $validated['teacher_id'] ?? $routine->teacher_id,
            'day_of_week' => $validated['day_of_week'] ?? $routine->day_of_week,
            'start_time' => $validated['start_time'] ?? $routine->start_time,
            'end_time' => $validated['end_time'] ?? $routine->end_time,
            'room' => array_key_exists('room', $validated) ? $validated['room'] : $routine->room,
            'academic_year_id' => array_key_exists('academic_year_id', $validated) ? $validated['academic_year_id'] : $routine->academic_year_id,
            'shift_id' => array_key_exists('shift_id', $validated) ? $validated['shift_id'] : $routine->shift_id,
            'period_id' => array_key_exists('period_id', $validated) ? $validated['period_id'] : $routine->period_id,
            'room_id' => array_key_exists('room_id', $validated) ? $validated['room_id'] : $routine->room_id,
        ];

        $this->validateRoutineRecords($request, $effective);
        $this->ensureNoConflicts($request, $effective, $routine);

        $routine->update($validated);

        return response()->json([
            'message' => 'Routine updated successfully.',
            'routine' => $routine->fresh()->load([
                'class',
                'section',
                'subject',
                'teacher.user',
            ]),
        ]);
    }

    public function destroy(Request $request, Routine $routine)
    {
        $this->checkSchoolAccess($request, $routine);
        $routine->delete();

        return response()->json([
            'message' => 'Routine deleted successfully.',
        ]);
    }

    private function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'class_id' => [$presence, 'integer', 'exists:school_classes,id'],
            'section_id' => [$presence, 'integer', 'exists:sections,id'],
            'subject_id' => [$presence, 'integer', 'exists:subjects,id'],
            'teacher_id' => [$presence, 'integer', 'exists:teachers,id'],
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_years,id'],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'exists:shifts,id'],
            'period_id' => ['sometimes', 'nullable', 'integer', 'exists:routine_periods,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:routine_rooms,id'],
            'day_of_week' => [$presence, Rule::in($this->days())],
            'start_time' => [$presence, 'date_format:H:i'],
            'end_time' => [$presence, 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function days(): array
    {
        return ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    }

    private function validateRoutineRecords(Request $request, array $values): void
    {
        $schoolId = $request->user()->school_id;
        $schoolClass = SchoolClass::find($values['class_id']);
        $section = Section::with('schoolClass')->find($values['section_id']);
        $subject = Subject::find($values['subject_id']);
        $teacher = Teacher::find($values['teacher_id']);

        if ($schoolClass?->school_id !== $schoolId
            || $section?->schoolClass?->school_id !== $schoolId
            || $subject?->school_id !== $schoolId
            || $teacher?->school_id !== $schoolId) {
            $this->abortUnauthorizedSchoolAccess();
        }

        foreach ([
            'academic_year_id' => AcademicYear::class,
            'shift_id' => Shift::class,
            'period_id' => RoutinePeriod::class,
            'room_id' => RoutineRoom::class,
        ] as $field => $model) {
            if (! empty($values[$field]) && ! $model::where('school_id', $schoolId)->whereKey($values[$field])->exists()) {
                $this->abortUnauthorizedSchoolAccess();
            }
        }

        if ((int) $section->school_class_id !== (int) $schoolClass->id) {
            abort(response()->json([
                'message' => 'Section does not belong to the selected class.',
            ], 422));
        }

        if (! TeacherAssignment::where('school_id', $schoolId)
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $schoolClass->id)
            ->where('section_id', $section->id)
            ->where('subject_id', $subject->id)
            ->exists()) {
            abort(response()->json([
                'message' => 'Teacher is not assigned to this class, section, and subject.',
            ], 422));
        }

        if (Carbon::createFromFormat('H:i', $this->normalizeTime($values['start_time']))
            ->gte(Carbon::createFromFormat('H:i', $this->normalizeTime($values['end_time'])))) {
            abort(response()->json([
                'message' => 'Start time must be before end time.',
            ], 422));
        }
    }

    private function normalizeTime(string $time): string
    {
        return substr($time, 0, 5);
    }

    private function ensureNoConflicts(
        Request $request,
        array $values,
        ?Routine $routine = null
    ): void {
        $baseQuery = Routine::where('school_id', $request->user()->school_id)
            ->where('day_of_week', $values['day_of_week'])
            ->where('start_time', '<', $values['end_time'])
            ->where('end_time', '>', $values['start_time']);

        if ($routine) {
            $baseQuery->where('id', '!=', $routine->id);
        }

        $this->checkConflict(
            clone $baseQuery,
            'teacher_id',
            $values['teacher_id'],
            'Teacher has a conflicting routine.'
        );

        $classSectionQuery = (clone $baseQuery)
            ->where('class_id', $values['class_id'])
            ->where('section_id', $values['section_id']);
        if ($classSectionQuery->exists()) {
            $this->abortConflict('Class and section have a conflicting routine.');
        }

        if (! empty($values['room'])) {
            $roomQuery = (clone $baseQuery)->where('room', $values['room']);
            $this->checkConflict($roomQuery, null, null, 'Room has a conflicting routine.');
        }
    }

    private function checkConflict(
        Builder $query,
        ?string $column,
        mixed $value,
        string $message
    ): void {
        if ($column && $value !== null) {
            $query->where($column, $value);
        }

        if ($query->exists()) {
            $this->abortConflict($message);
        }
    }

    private function checkSchoolAccess(Request $request, Routine $routine): void
    {
        if ($routine->school_id !== $request->user()->school_id) {
            $this->abortUnauthorizedSchoolAccess();
        }
    }

    private function abortConflict(string $message): never
    {
        abort(response()->json(['message' => $message], 422));
    }

    private function abortUnauthorizedSchoolAccess(): never
    {
        abort(response()->json([
            'message' => 'Unauthorized school access.',
        ], 403));
    }
}