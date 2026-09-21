<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use DatabaseTransactions;

    public function test_school_admin_can_create_an_exam(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/exams', [
                'name' => 'Midterm Exam',
                'code' => 'MID-2026',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Exam created successfully.');

        $this->assertDatabaseHas('exams', [
            'school_id' => $scenario['school']->id,
            'name' => 'Midterm Exam',
        ]);
    }

    public function test_exam_name_cannot_be_duplicated_within_same_school(): void
    {
        $scenario = $this->scenario();
        Exam::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Final Exam',
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/exams', ['name' => 'Final Exam'])
            ->assertStatus(422);
    }

    public function test_different_schools_can_use_the_same_exam_name(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();

        $firstResponse = $this->actingAs($first['admin'], 'sanctum')
            ->postJson('/api/exams', ['name' => 'Annual Exam']);
        $secondResponse = $this->actingAs($second['admin'], 'sanctum')
            ->postJson('/api/exams', ['name' => 'Annual Exam']);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
    }

    public function test_cross_school_exam_access_is_rejected(): void
    {
        $first = $this->scenario();
        $second = $this->scenario();
        $exam = Exam::create([
            'school_id' => $second['school']->id,
            'name' => 'School B Exam',
        ]);

        $this->actingAs($first['admin'], 'sanctum')
            ->getJson('/api/exams/'.$exam->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized school access.');
    }

    public function test_invalid_exam_date_range_is_rejected(): void
    {
        $scenario = $this->scenario();

        $this->actingAs($scenario['admin'], 'sanctum')
            ->postJson('/api/exams', [
                'name' => 'Invalid Exam',
                'start_date' => '2026-10-20',
                'end_date' => '2026-10-10',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'End date cannot be earlier than start date.');
    }

    public function test_exam_update_works(): void
    {
        $scenario = $this->scenario();
        $exam = Exam::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Original Exam',
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->putJson('/api/exams/'.$exam->id, [
                'name' => 'Updated Exam',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-05',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Exam updated successfully.');

        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'name' => 'Updated Exam',
        ]);
    }

    public function test_exam_delete_works(): void
    {
        $scenario = $this->scenario();
        $exam = Exam::create([
            'school_id' => $scenario['school']->id,
            'name' => 'Delete Exam',
        ]);

        $this->actingAs($scenario['admin'], 'sanctum')
            ->deleteJson('/api/exams/'.$exam->id)
            ->assertOk()
            ->assertJsonPath('message', 'Exam deleted successfully.');

        $this->assertDatabaseMissing('exams', ['id' => $exam->id]);
    }

    private function scenario(): array
    {
        $school = School::create([
            'name' => 'Exam School '.Str::random(8),
            'code' => 'EXM-'.Str::upper(Str::random(8)),
        ]);
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);

        return compact('school', 'admin');
    }
}