<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\FeeType;
use App\Models\Mark;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_dashboard_returns_system_wide_statistics(): void
    {
        $scenario = $this->scenario();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'school_id' => null]);
        $schoolCount = School::count();
        $studentCount = Student::count();
        $teacherCount = Teacher::count();

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/super-admin/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard.total_schools', $schoolCount)
            ->assertJsonPath('dashboard.total_students', $studentCount)
            ->assertJsonPath('dashboard.total_teachers', $teacherCount);
    }

    public function test_school_dashboard_is_school_scoped_and_has_fee_attendance_summary(): void
    {
        $scenario = $this->scenario();
        Attendance::create([
            'school_id' => $scenario['school']->id, 'student_id' => $scenario['student']->id,
            'class_id' => $scenario['class']->id, 'section_id' => $scenario['section']->id,
            'date' => today(), 'status' => 'present',
        ]);
        $fee = StudentFee::create([
            'school_id' => $scenario['school']->id, 'student_id' => $scenario['student']->id,
            'fee_type_id' => $scenario['feeType']->id, 'amount' => '100.00', 'status' => 'unpaid',
        ]);
        Payment::create([
            'school_id' => $scenario['school']->id, 'student_fee_id' => $fee->id,
            'student_id' => $scenario['student']->id, 'amount' => '40.00',
            'payment_date' => today(), 'payment_method' => 'cash',
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/school/dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard.total_students', 1)
            ->assertJsonPath('dashboard.attendance_today.present', 1)
            ->assertJsonPath('dashboard.fees.total_due', '60.00');
    }

    public function test_reports_return_school_scoped_data(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/students')
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.id', $first['student']->id);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/students?student_id='.$second['student']->id)
            ->assertOk()
            ->assertJsonCount(0, 'students');
    }

    public function test_attendance_fee_and_result_reports_work(): void
    {
        $scenario = $this->scenario();
        Attendance::create([
            'school_id' => $scenario['school']->id, 'student_id' => $scenario['student']->id,
            'class_id' => $scenario['class']->id, 'section_id' => $scenario['section']->id,
            'date' => '2026-09-21', 'status' => 'present',
        ]);
        $fee = StudentFee::create([
            'school_id' => $scenario['school']->id, 'student_id' => $scenario['student']->id,
            'fee_type_id' => $scenario['feeType']->id, 'amount' => '100.00', 'status' => 'unpaid',
        ]);
        Payment::create([
            'school_id' => $scenario['school']->id, 'student_fee_id' => $fee->id,
            'student_id' => $scenario['student']->id, 'amount' => '25.00',
            'payment_date' => '2026-09-21', 'payment_method' => 'cash',
        ]);
        $examSubject = ExamSubject::create([
            'school_id' => $scenario['school']->id, 'exam_id' => $scenario['exam']->id,
            'subject_id' => $scenario['subject']->id, 'full_marks' => 100, 'pass_marks' => 40,
        ]);
        Mark::create([
            'school_id' => $scenario['school']->id, 'exam_subject_id' => $examSubject->id,
            'student_id' => $scenario['student']->id, 'marks' => 85, 'grade' => 'A+', 'grade_point' => 5,
        ]);

        $admin = $scenario['admin'];
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/attendance?date=2026-09-21')->assertOk()->assertJsonCount(1, 'attendances');
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/fees')->assertOk()->assertJsonPath('total_paid', '25.00');
        $this->actingAs($admin, 'sanctum')->getJson('/api/reports/results?exam_id='.$scenario['exam']->id)->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_school_dashboard_is_not_available_to_a_user_without_school_access(): void
    {
        $user = User::factory()->create(['role' => 'student', 'school_id' => null]);

        $this->actingAs($user, 'sanctum')->getJson('/api/school/dashboard')->assertStatus(403);
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Dashboard School '.Str::random(8), 'code' => 'DSH-'.Str::upper(Str::random(8))]);
        $admin = User::factory()->create(['school_id' => $school->id, 'role' => 'school_admin']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Class '.Str::random(6), 'is_active' => true]);
        $section = Section::create(['school_class_id' => $class->id, 'name' => 'Section '.Str::random(6), 'is_active' => true]);
        $studentUser = User::factory()->create(['school_id' => $school->id, 'role' => 'student']);
        $student = Student::create(['user_id' => $studentUser->id, 'school_id' => $school->id, 'class_id' => $class->id, 'section_id' => $section->id]);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Subject '.Str::random(6), 'code' => 'SUB-'.Str::upper(Str::random(6)), 'is_active' => true]);
        $exam = Exam::create(['school_id' => $school->id, 'name' => 'Exam '.Str::random(6), 'is_active' => true]);
        $feeType = FeeType::create(['school_id' => $school->id, 'name' => 'Tuition '.Str::random(6), 'amount' => '100.00']);
        return compact('school', 'admin', 'student', 'class', 'section', 'subject', 'exam', 'feeType');
    }
}