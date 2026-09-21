<?php

namespace Tests\Feature;

use App\Models\Routine;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoutineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_school_admin_can_create_a_routine_with_valid_assignment(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', $this->payload($scenario))
            ->assertCreated()
            ->assertJsonPath('message', 'Routine created successfully.');
    }

    public function test_invalid_teacher_assignment_is_rejected(): void
    {
        $scenario = $this->scenario(false);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', $this->payload($scenario))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Teacher is not assigned to this class, section, and subject.');
    }

    public function test_teacher_conflict_is_rejected(): void
    {
        $scenario = $this->scenario();
        $this->createRoutine($scenario, [
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $second = $this->scenarioForExistingSchool($scenario['school']);
        TeacherAssignment::create([
            'school_id' => $scenario['school']->id,
            'teacher_id' => $scenario['teacher']->id,
            'class_id' => $second['class']->id,
            'section_id' => $second['section']->id,
            'subject_id' => $second['subject']->id,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', [
                ...$this->payload($second),
                'teacher_id' => $scenario['teacher']->id,
                'start_time' => '09:30',
                'end_time' => '10:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Teacher has a conflicting routine.');
    }

    public function test_class_section_conflict_is_rejected(): void
    {
        $scenario = $this->scenario();
        $this->createRoutine($scenario);
        $secondTeacher = $this->scenarioForExistingSchool($scenario['school']);
        $secondSubject = Subject::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Second Subject '.Str::random(6),
            'code' => 'SUB-'.Str::upper(Str::random(6)),
            'is_active' => true,
        ]);
        TeacherAssignment::create([
            'school_id' => $scenario['school']->id,
            'teacher_id' => $secondTeacher['teacher']->id,
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
            'subject_id' => $secondSubject->id,
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', [
                ...$this->payload($scenario),
                'subject_id' => $secondSubject->id,
                'teacher_id' => $secondTeacher['teacher']->id,
                'start_time' => '09:30',
                'end_time' => '10:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Class and section have a conflicting routine.');
    }

    public function test_room_conflict_is_rejected(): void
    {
        $scenario = $this->scenario();
        $this->createRoutine($scenario, ['room' => 'Room 1']);
        $second = $this->scenarioForExistingSchool($scenario['school']);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', [
                ...$this->payload($second),
                'room' => 'Room 1',
                'start_time' => '09:30',
                'end_time' => '10:30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room has a conflicting routine.');
    }

    public function test_invalid_time_range_is_rejected(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/routines', [
                ...$this->payload($scenario),
                'start_time' => '11:00',
                'end_time' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Start time must be before end time.');
    }

    public function test_cross_school_routine_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $routine = $this->createRoutine($second);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/routines/'.$routine->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_routine_update_works(): void
    {
        $scenario = $this->scenario();
        $routine = $this->createRoutine($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->putJson('/api/routines/'.$routine->id, [
                'day_of_week' => 'monday',
                'start_time' => '12:00',
                'end_time' => '13:00',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Routine updated successfully.');
    }

    public function test_routine_delete_works(): void
    {
        $scenario = $this->scenario();
        $routine = $this->createRoutine($scenario);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->deleteJson('/api/routines/'.$routine->id)
            ->assertOk()
            ->assertJsonPath('message', 'Routine deleted successfully.');

        $this->assertDatabaseMissing('routines', ['id' => $routine->id]);
    }

    public function test_routine_filtering_works(): void
    {
        $scenario = $this->scenario();
        $this->createRoutine($scenario, ['day_of_week' => 'monday']);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->getJson('/api/routines?day_of_week=monday&class_id='.$scenario['class']->id)
            ->assertOk()
            ->assertJsonCount(1, 'routines');
    }

    private function scenario(bool $withAssignment = true): array
    {
        $school = School::create([
            'name' => 'Routine School '.Str::random(8),
            'code' => 'RTN-'.Str::upper(Str::random(8)),
        ]);
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);

        $scenario = $this->scenarioForExistingSchool($school);
        $scenario['admin'] = $admin;

        if (! $withAssignment) {
            TeacherAssignment::where('school_id', $school->id)->delete();
        }

        return $scenario;
    }

    private function scenarioForExistingSchool(School $school): array
    {
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
        $subject = Subject::create([
            'school_id' => $school->id,
            'name' => 'Subject '.Str::random(6),
            'code' => 'SUB-'.Str::upper(Str::random(6)),
            'is_active' => true,
        ]);

        $scenario = compact('school', 'teacherUser', 'teacher', 'class', 'section', 'subject');

        TeacherAssignment::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);

        return $scenario;
    }

    private function payload(array $scenario): array
    {
        return [
            'class_id' => $scenario['class']->id,
            'section_id' => $scenario['section']->id,
            'subject_id' => $scenario['subject']->id,
            'teacher_id' => $scenario['teacher']->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => null,
            'is_active' => true,
        ];
    }

    private function createRoutine(array $scenario, array $overrides = []): Routine
    {
        return Routine::create([
            ...$this->payload($scenario),
            ...$overrides,
            'school_id' => $scenario['school']->id,
        ]);
    }
}