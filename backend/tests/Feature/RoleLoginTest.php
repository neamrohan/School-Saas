<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_dashboard_roles_authenticate_with_the_existing_sanctum_login(): void
    {
        $school = School::create([
            'name' => 'Login School',
            'code' => 'LOGIN',
            'email' => 'school@example.test',
            'is_active' => true,
        ]);
        $roles = ['super_admin', 'school_admin', 'teacher', 'student'];

        foreach ($roles as $index => $role) {
            $user = User::create([
                'name' => ucfirst(str_replace('_', ' ', $role)),
                'email' => "{$role}@example.test",
                'username' => "user{$index}",
                'password' => Hash::make('correct-password'),
                'school_id' => $role === 'super_admin' ? null : $school->id,
                'role' => $role,
            ]);

            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'correct-password',
                'role' => $role,
            ])->assertOk()->assertJsonPath('user.role', $role);

            $this->assertNotEmpty($response->json('token'));
        }
    }

    public function test_invalid_credentials_role_mismatch_and_unauthenticated_settings_are_rejected(): void
    {
        $school = School::create([
            'name' => 'Login School',
            'code' => 'LOGIN',
            'email' => 'school@example.test',
            'is_active' => true,
        ]);
        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'username' => 'teacher-login',
            'password' => Hash::make('correct-password'),
            'school_id' => $school->id,
            'role' => 'teacher',
        ]);

        $this->postJson('/api/login', [
            'email' => $teacher->email,
            'password' => 'wrong-password',
            'role' => 'teacher',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/login', [
            'email' => 'teacher-login',
            'password' => 'correct-password',
            'role' => 'teacher',
        ])->assertOk()->assertJsonPath('user.role', 'teacher');

        $this->postJson('/api/login', [
            'email' => $teacher->email,
            'password' => 'correct-password',
            'role' => 'student',
        ])->assertForbidden()->assertJsonPath('code', 'role_mismatch');

        $student = User::create([
            'name' => 'Student',
            'email' => 'student@example.test',
            'username' => 'student-login',
            'password' => Hash::make('correct-password'),
            'school_id' => $school->id,
            'role' => 'student',
        ]);

        $this->postJson('/api/login', [
            'email' => 'student-login',
            'password' => 'correct-password',
            'role' => 'teacher',
        ])->assertForbidden()->assertJsonPath('code', 'role_mismatch');

        $this->postJson('/api/login', [
            'email' => $student->email,
            'password' => 'correct-password',
            'role' => 'student',
        ])->assertOk()->assertJsonPath('user.role', 'student');

        $this->getJson('/api/school/website-settings')->assertUnauthorized();
    }
}
