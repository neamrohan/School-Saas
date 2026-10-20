<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SchoolRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_school_retains_data_and_revokes_its_users_tokens(): void
    {
        $school = $this->createSchool();
        $schoolAdmin = $this->createUser($school, 'school_admin', 'admin@example.test');
        $teacher = $this->createUser($school, 'teacher', 'teacher@example.test');
        $student = $this->createUser($school, 'student', 'student@example.test');
        $exam = Exam::create([
            'school_id' => $school->id,
            'name' => 'Annual exam',
        ]);
        $token = $schoolAdmin->createToken('existing-session');
        $superAdmin = $this->createUser(null, 'super_admin', 'super@example.test');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson('/api/schools/'.$school->id)
            ->assertOk()
            ->assertJsonPath('message', 'School removed successfully.');

        $this->assertSoftDeleted('schools', ['id' => $school->id]);
        $this->assertDatabaseHas('users', ['id' => $schoolAdmin->id, 'school_id' => $school->id]);
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'school_id' => $school->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'school_id' => $school->id]);
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'school_id' => $school->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)
            ->getJson('/api/me')
            ->assertUnauthorized();

        foreach ([
            [$schoolAdmin, '/api/school/dashboard'],
            [$teacher, '/api/teacher/assignments'],
            [$student, '/api/student-portal/classes'],
        ] as [$user, $schoolRoute]) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'role' => $user->role,
            ])
                ->assertForbidden()
                ->assertJsonPath('code', 'school_unavailable');

            $this->actingAs($user, 'sanctum')
                ->getJson($schoolRoute)
                ->assertForbidden()
                ->assertJsonPath('code', 'school_unavailable');
        }

        $this->postJson('/api/login', [
            'email' => $superAdmin->email,
            'password' => 'password',
            'role' => 'super_admin',
        ])->assertOk();
    }

    public function test_authenticated_school_users_are_blocked_after_school_removal(): void
    {
        $school = $this->createSchool();
        $schoolAdmin = $this->createUser($school, 'school_admin', 'admin@example.test');
        $superAdmin = $this->createUser(null, 'super_admin', 'super@example.test');

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/me')
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson('/api/schools/'.$school->id)
            ->assertOk();

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/school/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'school_unavailable');
    }

    public function test_super_admin_can_list_and_restore_a_school_and_its_users_can_log_in_again(): void
    {
        $school = $this->createSchool();
        $schoolAdmin = $this->createUser($school, 'school_admin', 'admin@example.test');
        $superAdmin = $this->createUser(null, 'super_admin', 'super@example.test');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson('/api/schools/'.$school->id)
            ->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/schools/deleted')
            ->assertOk()
            ->assertJsonPath('schools.0.id', $school->id);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/schools/'.$school->id.'/restore')
            ->assertOk()
            ->assertJsonPath('school.id', $school->id);

        $this->assertNotSoftDeleted('schools', ['id' => $school->id]);
        $this->assertDatabaseHas('users', ['id' => $schoolAdmin->id, 'school_id' => $school->id]);
        $this->postJson('/api/login', [
            'email' => $schoolAdmin->email,
            'password' => 'password',
            'role' => 'school_admin',
        ])->assertOk();

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/schools/deleted')
            ->assertOk()
            ->assertJsonCount(0, 'schools');
    }

    private function createSchool(): School
    {
        return School::create([
            'name' => 'School '.uniqid(),
            'code' => 'SCH-'.uniqid(),
        ]);
    }

    private function createUser(?School $school, string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('password'),
            'school_id' => $school?->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
