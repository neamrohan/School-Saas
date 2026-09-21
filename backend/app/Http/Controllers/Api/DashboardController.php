<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use App\Services\MoneyCalculator;
use Illuminate\Http\Request;

class DashboardController
{
    public function __construct(private readonly MoneyCalculator $money) {}

    public function superAdmin()
    {
        $schools = School::withCount(['students', 'teachers'])->latest()->get();

        return response()->json([
            'dashboard' => [
                'total_schools' => $schools->count(),
                'active_schools' => $schools->where('is_active', true)->count(),
                'inactive_schools' => $schools->where('is_active', false)->count(),
                'total_users' => User::count(),
                'total_teachers' => $schools->sum('teachers_count'),
                'total_students' => $schools->sum('students_count'),
                'total_parents' => User::where('role', 'parent')->count(),
                'schools' => $schools->map(fn (School $school) => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'student_count' => $school->students_count,
                    'teacher_count' => $school->teachers_count,
                    'is_active' => $school->is_active,
                ])->values(),
            ],
        ]);
    }

    public function school(Request $request)
    {
        $school = $request->user()->school;
        $schoolId = $school->id;
        $attendance = Attendance::where('school_id', $schoolId)
            ->whereDate('date', today())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $studentCount = $school->students()->count();
        $present = (int) $attendance->get('present', 0);
        $attendancePercentage = $studentCount > 0 ? round(($present / $studentCount) * 100, 2) : 0;
        $totalFees = $this->money->sum($school->studentFees()->pluck('amount'));
        $totalPaid = $this->money->sum($school->payments()->pluck('amount'));

        return response()->json([
            'message' => 'School access successful.',
            'school' => $school,
            'dashboard' => [
                'total_teachers' => $school->teachers()->count(),
                'total_students' => $studentCount,
                'total_parents' => $school->users()->where('role', 'parent')->count(),
                'total_classes' => $school->classes()->count(),
                'total_sections' => $school->sections()->count(),
                'total_subjects' => $school->subjects()->count(),
                'active_exams' => $school->exams()->where('is_active', true)->count(),
                'attendance_today' => [
                    'total_students' => $studentCount,
                    'present' => $present,
                    'absent' => (int) $attendance->get('absent', 0),
                    'late' => (int) $attendance->get('late', 0),
                    'excused' => (int) $attendance->get('excused', 0),
                    'attendance_percentage' => $attendancePercentage,
                ],
                'fees' => [
                    'total_fees' => $this->money->formatCents($totalFees),
                    'total_paid' => $this->money->formatCents($totalPaid),
                    'total_due' => $this->money->formatCents($totalFees - $totalPaid),
                ],
            ],
        ]);
    }
}