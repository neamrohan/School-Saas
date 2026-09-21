<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GroupTrade;
use App\Models\RoutinePeriod;
use App\Models\RoutineRoom;
use App\Models\Transport;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicSupportController extends Controller
{
    private array $resources = [
        'version' => [Version::class, 'versions', 'version'],
        'group' => [GroupTrade::class, 'groups', 'group'],
        'academic-year' => [AcademicYear::class, 'academic_years', 'academic_year'],
        'transport' => [Transport::class, 'transports', 'transport'],
        'routine-period' => [RoutinePeriod::class, 'routine_periods', 'routine_period'],
        'routine-room' => [RoutineRoom::class, 'routine_rooms', 'routine_room'],
    ];

    public function index(Request $request, string $resource)
    {
        [$model, $key] = $this->resource($resource);
        return response()->json([$key => $model::where('school_id', $request->user()->school_id)->latest()->get()]);
    }

    public function store(Request $request, string $resource)
    {
        [$model, $key, $singular] = $this->resource($resource);
        $validated = $this->validatePayload($request, $resource);
        $validated['school_id'] = $request->user()->school_id;
        $record = DB::transaction(function () use ($model, $validated, $resource, $request) {
            if ($resource === 'academic-year' && ($validated['is_current'] ?? false)) {
                $model::where('school_id', $request->user()->school_id)->update(['is_current' => false]);
            }
            return $model::create($validated);
        });
        return response()->json(['message' => ucfirst(str_replace('-', ' ', $resource)).' created successfully.', $singular => $record], 201);
    }

    public function show(Request $request, string $resource, string $id)
    {
        [, , $singular] = $this->resource($resource);
        $record = $this->findOwned($request, $resource, $id);
        return response()->json([$singular => $record]);
    }

    public function update(Request $request, string $resource, string $id)
    {
        [$model, , $singular] = $this->resource($resource);
        $record = $this->findOwned($request, $resource, $id);
        $validated = $this->validatePayload($request, $resource, $record);
        DB::transaction(function () use ($record, $validated, $resource, $request) {
            if ($resource === 'academic-year' && ($validated['is_current'] ?? false)) {
                AcademicYear::where('school_id', $request->user()->school_id)->where('id', '!=', $record->id)->update(['is_current' => false]);
            }
            $record->update($validated);
        });
        return response()->json(['message' => ucfirst(str_replace('-', ' ', $resource)).' updated successfully.', $singular => $record->fresh()]);
    }

    public function destroy(Request $request, string $resource, string $id)
    {
        $record = $this->findOwned($request, $resource, $id);
        $record->delete();
        return response()->json(['message' => ucfirst(str_replace('-', ' ', $resource)).' deleted successfully.']);
    }

    private function resource(string $resource): array
    {
        abort_unless(isset($this->resources[$resource]), 404);
        return $this->resources[$resource];
    }

    private function findOwned(Request $request, string $resource, string $id): Model
    {
        abort_unless(ctype_digit($id) && (int) $id > 0, 404, 'Invalid resource ID.');
        [$model] = $this->resource($resource);
        $record = $model::where('school_id', $request->user()->school_id)->find((int) $id);
        abort_unless($record, 403, 'Unauthorized school access.');
        return $record;
    }

    private function validatePayload(Request $request, string $resource, ?Model $record = null): array
    {
        $schoolId = $request->user()->school_id;
        $unique = fn (string $table) => Rule::unique($table, 'name')->where(fn ($query) => $query->where('school_id', $schoolId))->ignore($record?->id);
        $rules = match ($resource) {
            'version' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('versions')], 'is_active' => ['sometimes', 'boolean']],
            'group' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('groups_trades')], 'code' => ['sometimes', 'nullable', 'string', 'max:50'], 'type' => ['sometimes', 'nullable', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean']],
            'academic-year' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('academic_years')], 'start_date' => ['sometimes', 'nullable', 'date'], 'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'], 'is_current' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']],
            'transport' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('transports')], 'route' => ['sometimes', 'nullable', 'string', 'max:255'], 'vehicle_number' => ['sometimes', 'nullable', 'string', 'max:100'], 'driver_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'driver_phone' => ['sometimes', 'nullable', 'string', 'max:50'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']],
            'routine-period' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('routine_periods')], 'start_time' => ['sometimes', 'required', 'date_format:H:i', 'before:end_time'], 'end_time' => ['sometimes', 'required', 'date_format:H:i', 'after:start_time'], 'display_order' => ['sometimes', 'nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']],
            'routine-room' => ['name' => ['sometimes', 'required', 'string', 'max:255', $unique('routine_rooms')], 'code' => ['sometimes', 'nullable', 'string', 'max:50'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'is_active' => ['sometimes', 'boolean']],
        };
        return $request->validate($rules);
    }
}
