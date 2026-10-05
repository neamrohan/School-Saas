<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\FeeType;
use App\Models\Mark;
use App\Models\Routine;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_sign_in_with_username_and_selected_role(): void
    {
        $school = $this->school('Login school');
        User::factory()->create([
            'name' => 'Teacher Example',
            'email' => null,
            'username' => 'teacher.example',
            'password' => 'TeacherPass123',
            'role' => 'teacher',
            'school_id' => $school->id,
        ]);

        $this->postJson('/api/login', [
            'email' => 'teacher.example',
            'password' => 'TeacherPass123',
            'role' => 'teacher',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.school_id', $school->id);
    }

    public function test_existing_super_admin_and_school_admin_email_login_still_works(): void
    {
        $school = $this->school('Admin login school');
        User::factory()->create([
            'name' => 'System Admin',
            'email' => 'super@example.com',
            'password' => 'AdminPass123',
            'role' => 'super_admin',
            'school_id' => null,
        ]);
        User::factory()->create([
            'name' => 'School Admin',
            'email' => 'school@example.com',
            'password' => 'AdminPass123',
            'role' => 'school_admin',
            'school_id' => $school->id,
        ]);

        $this->postJson('/api/login', [
            'email' => 'super@example.com',
            'password' => 'AdminPass123',
            'role' => 'super_admin',
        ])->assertOk()->assertJsonPath('user.role', 'super_admin');

        $this->postJson('/api/login', [
            'email' => 'school@example.com',
            'password' => 'AdminPass123',
            'role' => 'school_admin',
        ])->assertOk()->assertJsonPath('user.role', 'school_admin');
    }

    public function test_login_rejects_a_selected_role_that_does_not_match_the_account(): void
    {
        User::factory()->create([
            'email' => 'role-check@example.com',
            'password' => 'TeacherPass123',
            'role' => 'teacher',
        ]);

        $this->postJson('/api/login', [
            'email' => 'role-check@example.com',
            'password' => 'TeacherPass123',
            'role' => 'student',
        ])->assertForbidden()
            ->assertJsonPath('code', 'role_mismatch')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'TeacherPass123',
            'role' => 'student',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'TeacherPass123',
            'role' => 'student',
        ])->assertForbidden()
            ->assertJsonPath('code', 'account_inactive')
            ->assertJsonPath('message', 'Your account is currently inactive. Please contact your school administrator.');
    }

    public function test_school_admin_creates_teacher_user_and_profile_in_their_school(): void
    {
        Storage::fake('public');
        $school = $this->school('Teacher account school');
        $admin = $this->schoolAdmin($school);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Class 8']);
        $section = Section::create(['school_class_id' => $class->id, 'name' => 'A']);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics', 'code' => 'MATH']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/teachers', [
            'name' => 'Rahim Ahmed',
            'username' => 'rahim.teacher',
            'password' => 'TeacherPass123',
            'password_confirmation' => 'TeacherPass123',
            'employee_id' => 'T-001',
            'designation' => 'Mathematics Teacher',
            'phone' => '01700000000',
            'class_id' => $class->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);

        $this->assertSame(201, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('teacher.user.role', 'teacher')
            ->assertJsonPath('teacher.user.school_id', $school->id)
            ->assertJsonPath('teacher.employee_id', 'T-001')
            ->assertJsonPath('teacher.assignments.0.class_id', $class->id)
            ->assertJsonPath('teacher.assignments.0.section_id', $section->id)
            ->assertJsonPath('teacher.assignments.0.subject_id', $subject->id)
            ->assertJsonMissingPath('teacher.user.password');

        $teacherUser = User::where('username', 'rahim.teacher')->firstOrFail();
        $this->assertSame($school->id, $teacherUser->school_id);
        $this->assertTrue(Hash::check('TeacherPass123', $teacherUser->password));

        $this->actingAs($admin, 'sanctum')->patchJson('/api/teachers/'.$response->json('teacher.id'), [
            'password' => 'ResetPass123',
            'password_confirmation' => 'ResetPass123',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('teacher.user.is_active', false);

        $this->assertTrue(Hash::check('ResetPass123', $teacherUser->fresh()->password));
        $this->postJson('/api/login', [
            'email' => 'rahim.teacher',
            'password' => 'ResetPass123',
            'role' => 'teacher',
        ])->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_school_admin_cannot_assign_student_to_another_schools_class(): void
    {
        $school = $this->school('Student account school');
        $otherSchool = $this->school('Other student school');
        $admin = $this->schoolAdmin($school);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026']);
        $otherClass = SchoolClass::create(['school_id' => $otherSchool->id, 'name' => 'Class 8']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/students', [
            'name' => 'Karim Ahmed',
            'email' => 'karim@example.com',
            'password' => 'StudentPass123',
            'password_confirmation' => 'StudentPass123',
            'academic_year_id' => $year->id,
            'class_id' => $otherClass->id,
        ]);
        $this->assertSame(422, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('message', 'Class not found in your school.');

        $this->assertDatabaseMissing('users', ['email' => 'karim@example.com']);
    }

    public function test_school_admin_creates_student_login_and_school_placement(): void
    {
        $school = $this->school('Student creation school');
        $admin = $this->schoolAdmin($school);
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026']);
        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'Class 8']);
        $section = Section::create(['school_class_id' => $class->id, 'name' => 'A']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/students', [
            'name' => 'Karim Ahmed',
            'username' => 'karim.student',
            'password' => 'StudentPass123',
            'password_confirmation' => 'StudentPass123',
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'student_id' => 'ST-1001',
            'roll' => '12',
        ]);

        $response->assertCreated()
            ->assertJsonPath('student.user.role', 'student')
            ->assertJsonPath('student.user.school_id', $school->id)
            ->assertJsonPath('student.student_id', 'ST-1001')
            ->assertJsonPath('student.roll', '12')
            ->assertJsonPath('student.class_id', $class->id)
            ->assertJsonMissingPath('student.user.password');

        $studentUser = User::where('username', 'karim.student')->firstOrFail();
        $this->assertNull($studentUser->email);
        $this->assertTrue(Hash::check('StudentPass123', $studentUser->password));

        $this->actingAs($admin, 'sanctum')->patchJson('/api/students/'.$response->json('student.id'), [
            'student_id' => 'ST-1002',
            'roll' => '13',
            'class_id' => $class->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
            'password' => 'ResetStudent123',
            'password_confirmation' => 'ResetStudent123',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('student.student_id', 'ST-1002')
            ->assertJsonPath('student.roll', '13')
            ->assertJsonPath('student.class_id', $class->id)
            ->assertJsonPath('student.section_id', $section->id)
            ->assertJsonPath('student.academic_year_id', $year->id)
            ->assertJsonPath('student.user.is_active', false)
            ->assertJsonPath('student.is_active', false);

        $this->assertTrue(Hash::check('ResetStudent123', $studentUser->fresh()->password));
        $this->postJson('/api/login', [
            'email' => 'karim.student',
            'password' => 'ResetStudent123',
            'role' => 'student',
        ])->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_student_can_only_load_their_own_profile_not_school_student_list(): void
    {
        $school = $this->school('Student profile school');
        $academicYear = AcademicYear::create(['school_id' => $school->id, 'name' => '2026']);
        $studentUser = User::factory()->create([
            'role' => 'student',
            'school_id' => $school->id,
        ]);
        Student::create(['user_id' => $studentUser->id, 'school_id' => $school->id, 'student_id' => 'ST-1001', 'roll' => '12', 'academic_year_id' => $academicYear->id]);
        $otherUser = User::factory()->create(['role' => 'student', 'school_id' => $school->id]);
        Student::create(['user_id' => $otherUser->id, 'school_id' => $school->id, 'student_id' => 'ST-1002']);

        $this->actingAs($studentUser, 'sanctum')
            ->getJson('/api/students')
            ->assertForbidden();

        $profileResponse = $this->getJson('/api/me/profile');
        $this->assertSame(200, $profileResponse->status(), $profileResponse->getContent());
        $profileResponse
            ->assertJsonPath('student.student_id', 'ST-1001')
            ->assertJsonPath('student.academic_year.name', '2026')
            ->assertJsonMissing(['student_id' => 'ST-1002']);
    }

    public function test_student_portal_pages_only_return_the_authenticated_students_records(): void
    {
        $school = $this->school('Student portal school');
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026']);
        $ownClass = SchoolClass::create(['school_id' => $school->id, 'name' => 'Class 8']);
        $otherClass = SchoolClass::create(['school_id' => $school->id, 'name' => 'Class 9']);
        $ownSection = Section::create(['school_class_id' => $ownClass->id, 'name' => 'A']);
        $otherSection = Section::create(['school_class_id' => $otherClass->id, 'name' => 'B']);
        $ownSubject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics', 'code' => 'MATH']);
        $otherSubject = Subject::create(['school_id' => $school->id, 'name' => 'Science', 'code' => 'SCI']);
        DB::table('class_subject_assignments')->insert([
            ['school_id' => $school->id, 'school_class_id' => $ownClass->id, 'subject_id' => $ownSubject->id, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school->id, 'school_class_id' => $otherClass->id, 'subject_id' => $otherSubject->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $ownUser = User::factory()->create(['school_id' => $school->id, 'role' => 'student']);
        $otherUser = User::factory()->create(['school_id' => $school->id, 'role' => 'student']);
        $ownStudent = Student::create(['user_id' => $ownUser->id, 'school_id' => $school->id, 'class_id' => $ownClass->id, 'section_id' => $ownSection->id, 'academic_year_id' => $year->id]);
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'school_id' => $school->id, 'class_id' => $otherClass->id, 'section_id' => $otherSection->id, 'academic_year_id' => $year->id]);
        $ownAttendance = Attendance::create(['school_id' => $school->id, 'student_id' => $ownStudent->id, 'class_id' => $ownClass->id, 'section_id' => $ownSection->id, 'date' => '2026-10-01', 'status' => 'present']);
        $otherAttendance = Attendance::create(['school_id' => $school->id, 'student_id' => $otherStudent->id, 'class_id' => $otherClass->id, 'section_id' => $otherSection->id, 'date' => '2026-10-01', 'status' => 'absent']);
        $ownExam = Exam::create(['school_id' => $school->id, 'academic_year_id' => $year->id, 'class_id' => $ownClass->id, 'name' => 'Midterm 8', 'is_active' => true]);
        $otherExam = Exam::create(['school_id' => $school->id, 'academic_year_id' => $year->id, 'class_id' => $otherClass->id, 'name' => 'Midterm 9', 'is_active' => true]);
        $ownExamSubject = ExamSubject::create(['school_id' => $school->id, 'exam_id' => $ownExam->id, 'subject_id' => $ownSubject->id, 'full_marks' => 100, 'pass_marks' => 40]);
        $otherExamSubject = ExamSubject::create(['school_id' => $school->id, 'exam_id' => $otherExam->id, 'subject_id' => $otherSubject->id, 'full_marks' => 100, 'pass_marks' => 40]);
        $ownMark = Mark::create(['school_id' => $school->id, 'exam_subject_id' => $ownExamSubject->id, 'student_id' => $ownStudent->id, 'marks' => 81]);
        Mark::create(['school_id' => $school->id, 'exam_subject_id' => $otherExamSubject->id, 'student_id' => $otherStudent->id, 'marks' => 52]);
        $feeType = FeeType::create(['school_id' => $school->id, 'name' => 'Tuition', 'amount' => 500, 'is_active' => true]);
        $ownFee = StudentFee::create(['school_id' => $school->id, 'student_id' => $ownStudent->id, 'fee_type_id' => $feeType->id, 'amount' => 500, 'status' => 'unpaid']);
        StudentFee::create(['school_id' => $school->id, 'student_id' => $otherStudent->id, 'fee_type_id' => $feeType->id, 'amount' => 700, 'status' => 'unpaid']);
        $teacherUser = User::factory()->create(['school_id' => $school->id, 'role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'school_id' => $school->id]);
        Routine::create(['school_id' => $school->id, 'class_id' => $ownClass->id, 'section_id' => $ownSection->id, 'subject_id' => $ownSubject->id, 'teacher_id' => $teacher->id, 'day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => true]);
        Routine::create(['school_id' => $school->id, 'class_id' => $otherClass->id, 'section_id' => $otherSection->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $teacher->id, 'day_of_week' => 'monday', 'start_time' => '10:00', 'end_time' => '11:00', 'is_active' => true]);

        $this->actingAs($ownUser, 'sanctum');

        $this->getJson('/api/student-portal/classes')->assertOk()
            ->assertJsonPath('student.id', $ownStudent->id)
            ->assertJsonCount(1, 'subjects')
            ->assertJsonPath('subjects.0.name', 'Mathematics');
        $this->getJson('/api/student-portal/attendance')->assertOk()
            ->assertJsonCount(1, 'attendance')
            ->assertJsonPath('attendance.0.id', $ownAttendance->id)
            ->assertJsonMissing(['id' => $otherAttendance->id]);
        $this->getJson('/api/student-portal/results')->assertOk()
            ->assertJsonCount(1, 'marks')
            ->assertJsonPath('marks.0.id', $ownMark->id);
        $this->getJson('/api/student-portal/routine')->assertOk()
            ->assertJsonCount(1, 'routines')
            ->assertJsonPath('routines.0.class_id', $ownClass->id);
        $this->getJson('/api/student-portal/fees')->assertOk()
            ->assertJsonCount(1, 'student_fees')
            ->assertJsonPath('student_fees.0.id', $ownFee->id);
    }

    public function test_deactivated_existing_token_is_revoked(): void
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/me/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_teacher_can_update_own_personal_profile_and_password(): void
    {
        $school = $this->school('Teacher profile school');
        $user = User::factory()->create(['school_id' => $school->id, 'role' => 'teacher']);
        Teacher::create(['user_id' => $user->id, 'school_id' => $school->id, 'employee_id' => 'T-001']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/me/profile', [
            'name' => 'Updated Teacher',
            'phone' => '01712345678',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Updated Teacher')
            ->assertJsonPath('teacher.phone', '01712345678');

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'UpdatedPass123',
            'password_confirmation' => 'UpdatedPass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('UpdatedPass123', $user->fresh()->password));
    }

    private function school(string $name): School
    {
        return School::create(['name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)).'-'.fake()->unique()->numerify('####')]);
    }

    private function schoolAdmin(School $school): User
    {
        return User::factory()->create(['school_id' => $school->id, 'role' => 'school_admin']);
    }
}
