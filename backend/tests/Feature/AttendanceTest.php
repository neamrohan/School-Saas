<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_school_admin_can_create_attendance(): void
    {
        $scenario = $this->scenario();

        $response = $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/attendances', $this->attendancePayload($scenario));

        $response->assertCreated()
            ->assertJsonPath('message', 'Attendance created successfully.');
        $this->assertDatabaseHas('attendances', [
            'student_id' => $scenario['student']->id,
            'date' => '2026-09-21',
        ]);
    }

    public function test_duplicate_attendance_for_student_and_date_is_rejected(): void
    {
        $scenario = $this->scenario();
        Attendance::create([
            ...$this->attendancePayload($scenario),
            'school_id' => $scenario['school']->id,
        ]);

        $response = $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/attendances', $this->attendancePayload($scenario));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Attendance already exists for this student on this date.');
    }

    public function test_cross_school_attendance_access_is_rejected(): void
    {
        $schoolA = $this->scenario();
        $schoolB = $this->scenario();
        $attendance = Attendance::create([
            ...$this->attendancePayload($schoolB),
            'school_id' => $schoolB['school']->id,
        ]);

        $this->actingAs($schoolA['admin'], 'sanctum')
            ->getJson('/api/attendances/'.$attendance->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_invalid_student_class_section_combination_is_rejected(): void
    {
        $scenario = $this->scenario();
        $otherClass = SchoolClass::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Other Class '.Str::random(6),
            'is_active' => true,
        ]);
        $otherSection = Section::create([
            'school_class_id' => $otherClass->id,
            'name' => 'Other Section',
            'is_active' => true,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/attendances', [
                ...$this->attendancePayload($scenario),
                'class_id' => $otherClass->id,
                'section_id' => $otherSection->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Student, class, and section do not match.');
    }

    public function test_teacher_cannot_manage_an_unassigned_class_section(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['teacherUser'], 'sanctum')
            ->postJson('/api/attendances', $this->attendancePayload($scenario))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_teacher_can_manage_an_assigned_class_section(): void
    {
        $scenario = $this->scenario();
        TeacherAssignment::create([
            'school_id' => $scenario['school']->id,
            'teacher_id' => $scenario['teacher']->id,
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
            'subject_id' => $scenario['subject']->id,
        ]);

        $this->actingAs($scenario['teacherUser'], 'sanctum')
            ->postJson('/api/attendances', $this->attendancePayload($scenario))
            ->assertCreated()
            ->assertJsonPath('message', 'Attendance created successfully.');
    }

    private function scenario(): array
    {
        $school = School::create([
            'name' => 'Attendance School '.Str::random(8),
            'code' => 'ATT-'.Str::upper(Str::random(8)),
        ]);
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'name' => 'Class '.Str::random(6),
            'is_active' => true,
        ]);
        $section = Section::create([
            'school_class_id' => $class->id,
            'name' => 'Section '.Str::random(6),
            'is_active' => true,
        ]);
        $studentUser = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'student',
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
        ]);
        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(6),
            'code' => 'SUB-'.Str::upper(Str::random(6)),
            'is_active' => true,
        ]);
        $teacherUser = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'teacher',
        ]);
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'school_id' => $school->id,
        ]);

        return compact(
            'school',
            'admin',
            'class',
            'section',
            'student',
            'subject',
            'teacher',
            'teacherUser',
        );
    }

    private function attendancePayload(array $scenario): array
    {
        return [
            'student_id' => $scenario['student']->id,
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
            'date' => '2026-09-21',
            'status' => 'present',
        ];
    }
}