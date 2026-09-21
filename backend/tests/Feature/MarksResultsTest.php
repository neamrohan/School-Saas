<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Mark;
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

class MarksResultsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_school_admin_can_create_an_exam_subject(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/exam-subjects', [
                'exam_id' => $scenario['exam']->id,
                'subject_id' => $scenario['subject']->id,
                'full_marks' => 100,
                'pass_marks' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Exam subject created successfully.');
    }

    public function test_duplicate_exam_subject_assignment_is_rejected(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/exam-subjects', [
                'exam_id' => $examSubject->exam_id,
                'subject_id' => $examSubject->subject_id,
                'full_marks' => 100,
                'pass_marks' => 40,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This subject is already assigned to the exam.');
    }

    public function test_school_admin_can_create_marks(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 85))
            ->assertCreated()
            ->assertJsonPath('message', 'Mark created successfully.');
    }

    public function test_marks_greater_than_full_marks_are_rejected(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 101))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Marks cannot exceed the exam subject full marks.');
    }

    public function test_negative_marks_are_rejected(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, -1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('marks');
    }

    public function test_grade_and_grade_point_are_calculated(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 85))
            ->assertCreated()
            ->assertJsonPath('mark.grade', 'A+')
            ->assertJsonPath('mark.grade_point', '5.00');
    }

    public function test_duplicate_marks_for_student_and_exam_subject_are_rejected(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);
        Mark::create([
            ...$this->markPayload($scenario, $examSubject, 85),
            'school_id' => $scenario['school']->id,
            'grade' => 'A+',
            'grade_point' => 5,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 80))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A mark already exists for this student and exam subject.');
    }

    public function test_assigned_teacher_can_manage_marks(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);
        $this->assignTeacher($scenario, $examSubject);

        $this->actingAs($scenario['teacherUser'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 75))
            ->assertCreated();
    }

    public function test_unassigned_teacher_cannot_manage_marks(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);

        $this->actingAs($scenario['teacherUser'], 'sanctum')
            ->postJson('/api/marks', $this->markPayload($scenario, $examSubject, 75))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_cross_school_mark_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $examSubject = $this->examSubject($second);
        $mark = Mark::create([
            ...$this->markPayload($second, $examSubject, 80),
            'school_id' => $second['school']->id,
            'grade' => 'A+',
            'grade_point' => 5,
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/marks/'.$mark->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_student_result_endpoint_returns_calculated_result(): void
    {
        $scenario = $this->scenario();
        $examSubject = $this->examSubject($scenario);
        Mark::create([
            ...$this->markPayload($scenario, $examSubject, 85),
            'school_id' => $scenario['school']->id,
            'grade' => 'A+',
            'grade_point' => 5,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/students/'.$scenario['student']->id.'/results/'.$scenario['exam']->id)
            ->assertOk()
            ->assertJsonPath('total_marks', 100)
            ->assertJsonPath('obtained_marks', 85)
            ->assertJsonPath('overall_gpa', 5)
            ->assertJsonPath('passed', true)
            ->assertJsonPath('subjects.0.grade', 'A+');
    }

    public function test_cross_school_student_result_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/students/'.$second['student']->id.'/results/'.$second['exam']->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    private function scenario(): array
    {
        $school = School::create([
            'name' => 'Marks School '.Str::random(8),
            'code' => 'MRK-'.Str::upper(Str::random(8)),
        ]);
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);
        $teacherUser = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'teacher',
        ]);
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'school_id' => $school->id,
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
        $exam = Exam::create([
            'school_id' => $school->id,
            'name' => 'Exam '.Str::random(6),
            'is_active' => true,
        ]);

        return compact(
            'school',
            'admin',
            'teacherUser',
            'teacher',
            'class',
            'section',
            'student',
            'subject',
            'exam',
        );
    }

    private function examSubject(array $scenario): ExamSubject
    {
        return ExamSubject::create([
            'school_id' => $scenario['school']->id,
            'exam_id' => $scenario['exam']->id,
            'subject_id' => $scenario['subject']->id,
            'full_marks' => 100,
            'pass_marks' => 40,
        ]);
    }

    private function assignTeacher(array $scenario, ExamSubject $examSubject): TeacherAssignment
    {
        return TeacherAssignment::create([
            'school_id' => $scenario['school']->id,
            'teacher_id' => $scenario['teacher']->id,
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
            'subject_id' => $examSubject->subject_id,
        ]);
    }

    private function markPayload(array $scenario, ExamSubject $examSubject, int|float $marks): array
    {
        return [
            'exam_subject_id' => $examSubject->id,
            'student_id' => $scenario['student']->id,
            'marks' => $marks,
        ];
    }
}