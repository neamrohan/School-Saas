<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SchoolContactMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_form_creates_a_school_scoped_message_and_admin_can_manage_it(): void
    {
        $school = $this->createSchool('CONTACT-A');
        $admin = $this->createAdmin($school, 'admin-a@example.test');

        $this->postJson("/api/public/schools/{$school->code}/contact", [
            'name' => 'Visitor Name',
            'email' => 'visitor@example.test',
            'phone' => '+123456789',
            'message' => 'Please send admission information.',
        ])->assertCreated()->assertJsonPath('message', 'Your message has been sent.');

        $message = SchoolContactMessage::query()->firstOrFail();
        $this->assertSame($school->id, $message->school_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/school/contact-messages')
            ->assertOk()
            ->assertJsonPath('messages.data.0.email', 'visitor@example.test')
            ->assertJsonMissingPath('messages.data.0.school_id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/school/contact-messages/{$message->id}/read")
            ->assertOk();
        $this->assertNotNull($message->fresh()->read_at);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/school/contact-messages/{$message->id}")
            ->assertOk();
        $this->assertDatabaseMissing('school_contact_messages', ['id' => $message->id]);
    }

    public function test_contact_messages_are_private_to_their_school_and_public_validation_rejects_bad_data(): void
    {
        $schoolA = $this->createSchool('CONTACT-A');
        $schoolB = $this->createSchool('CONTACT-B');
        $adminB = $this->createAdmin($schoolB, 'admin-b@example.test');
        $message = $schoolA->contactMessages()->create([
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'message' => 'Hello school.',
        ]);

        $this->postJson("/api/public/schools/{$schoolA->code}/contact", [
            'name' => '',
            'email' => 'not-an-email',
            'message' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->actingAs($adminB, 'sanctum')
            ->patchJson("/api/school/contact-messages/{$message->id}/read")
            ->assertNotFound();
    }

    public function test_super_admin_can_view_a_selected_schools_contact_messages(): void
    {
        $school = $this->createSchool('CONTACT-C');
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);
        $school->contactMessages()->create([
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'message' => 'A question for the school.',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/schools/{$school->id}/contact-messages")
            ->assertOk()
            ->assertJsonPath('messages.data.0.email', 'visitor@example.test');
    }

    private function createSchool(string $code): School
    {
        return School::create([
            'name' => "School {$code}",
            'code' => $code,
            'email' => strtolower($code).'@example.test',
            'is_active' => true,
        ]);
    }

    private function createAdmin(School $school, string $email): User
    {
        return User::create([
            'name' => 'School Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'school_id' => $school->id,
            'role' => 'school_admin',
        ]);
    }
}
