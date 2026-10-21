<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
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
use App\Models\WaiverType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_school_admin_report_endpoints_are_school_scoped_and_return_collection_summaries(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $year = AcademicYear::create([
            'school_id' => $first['school']->id,
            'name' => 'Report session '.Str::random(6),
            'is_active' => true,
        ]);
        $first['student']->update(['academic_year_id' => $year->id]);
        $fee = StudentFee::create([
            'school_id' => $first['school']->id,
            'student_id' => $first['student']->id,
            'fee_type_id' => $first['feeType']->id,
            'academic_year_id' => $year->id,
            'class_id' => $first['class']->id,
            'amount' => '100.00',
            'status' => 'partial',
        ]);
        Payment::create([
            'school_id' => $first['school']->id,
            'student_fee_id' => $fee->id,
            'student_id' => $first['student']->id,
            'amount' => '25.00',
            'payment_date' => '2026-10-05',
            'payment_method' => 'cash',
        ]);
        $admissionFeeType = FeeType::create([
            'school_id' => $first['school']->id,
            'name' => 'Admission fee '.Str::random(5),
            'amount' => '10.00',
        ]);
        $admissionFee = StudentFee::create([
            'school_id' => $first['school']->id,
            'student_id' => $first['student']->id,
            'fee_type_id' => $admissionFeeType->id,
            'academic_year_id' => $year->id,
            'class_id' => $first['class']->id,
            'amount' => '10.00',
            'status' => 'paid',
        ]);
        Payment::create([
            'school_id' => $first['school']->id,
            'student_fee_id' => $admissionFee->id,
            'student_id' => $first['student']->id,
            'amount' => '10.00',
            'payment_date' => '2026-10-06',
            'payment_method' => 'bank',
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/admissions?academic_year_id='.$year->id)
            ->assertOk()
            ->assertJsonPath('total_admissions', 1)
            ->assertJsonPath('session_counts.0.count', 1);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/collections?academic_year_id='.$year->id.'&class_id='.$first['class']->id)
            ->assertOk()
            ->assertJsonPath('payment_count', 2)
            ->assertJsonPath('total_collected', '35.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/admission-collections')
            ->assertOk()
            ->assertJsonPath('payment_count', 1)
            ->assertJsonPath('total_collected', '10.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/collections?student_id='.$second['student']->id)
            ->assertOk()
            ->assertJsonCount(0, 'payments')
            ->assertJsonPath('total_collected', '0.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/monthly-collections?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('months.0.month', '2026-10')
            ->assertJsonPath('months.0.total_collected', '35.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/cash-book')
            ->assertOk()
            ->assertJsonPath('total_collected', '25.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/bank-book')
            ->assertOk()
            ->assertJsonPath('total_collected', '10.00');

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/reports/fees?class_id='.$first['class']->id.'&academic_year_id='.$year->id)
            ->assertOk()
            ->assertJsonPath('total_due', '75.00');
    }

    public function test_online_admission_can_be_submitted_and_approved_without_duplicate_students(): void
    {
        $scenario = $this->scenario();
        $year = AcademicYear::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Application session '.Str::random(5),
            'is_active' => true,
        ]);
        $scenario['student']->update(['academic_year_id' => $year->id]);
        $payload = [
            'name' => 'Online Applicant',
            'email' => 'online-'.Str::lower(Str::random(8)).'@example.test',
            'phone' => '555-0100',
            'guardian_name' => 'Guardian',
            'guardian_phone' => '555-0199',
            'academic_year_id' => $year->id,
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
        ];

        $response = $this->postJson('/api/public/schools/'.$scenario['school']->code.'/admissions', $payload)
            ->assertCreated()
            ->assertJsonPath('application.status', 'pending');
        $applicationId = $response->json('application.id');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/online-admissions')
            ->assertOk()
            ->assertJsonPath('applications.data.0.id', $applicationId);

        $approval = $this->actingAs($scenario['admin'], 'sanctum')
            ->patchJson('/api/online-admissions/'.$applicationId.'/review', ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('student.student_id', 'ADM-'.$scenario['school']->id.'-'.$applicationId);

        $this->assertNotEmpty($approval->json('temporary_password'));
        $this->assertDatabaseHas('online_admission_applications', [
            'id' => $applicationId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseCount('students', 2);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->patchJson('/api/online-admissions/'.$applicationId.'/review', ['decision' => 'approve'])
            ->assertStatus(409);
    }

    public function test_re_admission_and_bulk_profile_updates_are_school_scoped(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $previousYear = AcademicYear::create([
            'school_id' => $first['school']->id,
            'name' => 'Previous session '.Str::random(5),
            'is_active' => true,
        ]);
        $first['student']->update(['academic_year_id' => $previousYear->id]);
        $nextYear = AcademicYear::create([
            'school_id' => $first['school']->id,
            'name' => 'Next session '.Str::random(5),
            'is_active' => true,
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->postJson('/api/students/'.$first['student']->id.'/re-admit', [
                'academic_year_id' => $nextYear->id,
                'class_id' => $first['class']->id,
                'section_id' => $first['section']->id,
            ])
            ->assertCreated()
            ->assertJsonPath('re_admission.previous_academic_year_id', $first['student']->academic_year_id);

        $this->assertDatabaseHas('student_re_admissions', [
            'student_id' => $first['student']->id,
            'academic_year_id' => $nextYear->id,
        ]);
        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/student-re-admissions?academic_year_id='.$nextYear->id)
            ->assertOk()
            ->assertJsonPath('re_admissions.data.0.student.id', $first['student']->id);
        $this->actingAs($first['admin'], 'sanctum')
            ->postJson('/api/students/'.$first['student']->id.'/re-admit', ['academic_year_id' => $nextYear->id])
            ->assertStatus(422);

        $this->actingAs($first['admin'], 'sanctum')
            ->postJson('/api/students/bulk-update', [
                'student_ids' => [$first['student']->id],
                'phone' => '555-1234',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('updated', 1);
        $this->assertDatabaseHas('students', ['id' => $first['student']->id, 'phone' => '555-1234', 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $first['student']->user_id, 'is_active' => false]);

        $this->actingAs($first['admin'], 'sanctum')
            ->postJson('/api/students/bulk-update', ['student_ids' => [$second['student']->id], 'phone' => '555-0000'])
            ->assertNotFound();
    }

    public function test_waivers_are_school_scoped_audited_and_subtracted_from_due_balances(): void
    {
        $scenario = $this->scenario();
        $fee = StudentFee::create([
            'school_id' => $scenario['school']->id,
            'student_id' => $scenario['student']->id,
            'fee_type_id' => $scenario['feeType']->id,
            'amount' => '100.00',
            'status' => 'unpaid',
        ]);
        $waiverType = WaiverType::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Partial '.Str::random(5),
            'calculation_type' => 'percentage',
            'value' => '25.00',
            'is_active' => true,
        ]);

        $assignment = $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/student-waivers', [
                'student_id' => $scenario['student']->id,
                'student_fee_id' => $fee->id,
                'waiver_type_id' => $waiverType->id,
                'reason' => 'Eligible support',
            ])
            ->assertCreated()
            ->assertJsonPath('waiver.amount', '25.00');
        $waiverId = $assignment->json('waiver.id');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/reports/fees?student_id='.$scenario['student']->id)
            ->assertOk()
            ->assertJsonPath('total_waived', '25.00')
            ->assertJsonPath('total_due', '75.00');

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/student-waivers/'.$waiverId.'/revoke', [])
            ->assertOk();
        $this->assertDatabaseHas('student_waivers', [
            'id' => $waiverId,
            'status' => 'revoked',
            'revoked_by' => $scenario['admin']->id,
        ]);
        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/student-waivers?status=revoked')
            ->assertOk()
            ->assertJsonPath('waivers.data.0.id', $waiverId);
    }

    public function test_payment_updates_cannot_exceed_balance_after_active_waivers(): void
    {
        $scenario = $this->scenario();
        $fee = StudentFee::create([
            'school_id' => $scenario['school']->id,
            'student_id' => $scenario['student']->id,
            'fee_type_id' => $scenario['feeType']->id,
            'amount' => '100.00',
            'status' => 'unpaid',
        ]);
        $waiverType = WaiverType::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Fixed '.Str::random(5),
            'calculation_type' => 'fixed',
            'value' => '25.00',
            'is_active' => true,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/student-waivers', [
                'student_id' => $scenario['student']->id,
                'student_fee_id' => $fee->id,
                'waiver_type_id' => $waiverType->id,
            ])
            ->assertCreated();

        $payment = $this->postJson('/api/payments', [
            'student_fee_id' => $fee->id,
            'student_id' => $scenario['student']->id,
            'amount' => '50.00',
            'payment_date' => today()->toDateString(),
            'payment_method' => 'cash',
        ])->assertCreated()->json('payment');

        $this->patchJson('/api/payments/'.$payment['id'], ['amount' => '76.00'])->assertStatus(422);
        $this->patchJson('/api/payments/'.$payment['id'], ['amount' => '75.00'])->assertOk();
        $this->assertDatabaseHas('student_fees', ['id' => $fee->id, 'status' => 'paid']);
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