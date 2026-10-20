<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolWebsiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolWebsiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_only_read_and_update_its_own_website_settings(): void
    {
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        $adminA = $this->createAdmin($schoolA, 'admin-a@example.test');
        $adminB = $this->createAdmin($schoolB, 'admin-b@example.test');

        $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/school/website-settings')
            ->assertOk()
            ->assertJsonPath('settings.school_id', $schoolA->id)
            ->assertJsonPath('settings.website_name', $schoolA->name);

        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'school_id' => $schoolB->id,
                'website_name' => 'Updated A Website',
                'tagline' => 'Learning together',
                'primary_color' => '#123456',
            ])
            ->assertOk()
            ->assertJsonPath('settings.school_id', $schoolA->id)
            ->assertJsonPath('settings.website_name', 'Updated A Website');

        $this->actingAs($adminB, 'sanctum')
            ->getJson('/api/school/website-settings')
            ->assertOk()
            ->assertJsonPath('settings.school_id', $schoolB->id)
            ->assertJsonPath('settings.website_name', $schoolB->name);

        $this->assertDatabaseHas('school_website_settings', [
            'school_id' => $schoolA->id,
            'website_name' => 'Updated A Website',
        ]);
        $this->assertDatabaseHas('school_website_settings', [
            'school_id' => $schoolB->id,
            'website_name' => $schoolB->name,
        ]);
        $this->assertSame(2, SchoolWebsiteSetting::query()->count());
    }

    public function test_only_school_admins_can_use_the_settings_management_endpoints(): void
    {
        $school = $this->createSchool('A');
        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@example.test',
            'password' => Hash::make('password'),
            'school_id' => $school->id,
            'role' => 'teacher',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/school/website-settings')
            ->assertForbidden();

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/school/website-settings', ['website_name' => 'Not allowed'])
            ->assertForbidden();
    }

    public function test_school_admin_can_upload_logo_and_favicon_and_public_website_uses_saved_settings(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('A');
        $admin = $this->createAdmin($school, 'admin-a@example.test');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'website_name' => 'A Public School',
                'meta_description' => 'School description for search.',
                'tagline' => 'A great place to learn',
                'contact_email' => 'contact@example.test',
                'about' => 'Our school description.',
                'primary_color' => '#245678',
                'secondary_color' => '#f0f2f4',
                'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->onePixelPng()),
                'favicon' => UploadedFile::fake()->createWithContent('favicon.png', $this->onePixelPng()),
            ])
            ->assertOk()
            ->assertJsonPath('settings.website_name', 'A Public School')
            ->assertJsonPath('settings.meta_description', 'School description for search.')
            ->assertJsonPath('settings.primary_color', '#245678');

        $settings = $response->json('settings');
        $logoPath = $this->storagePathFromUrl($settings['logo_url']);
        $faviconPath = $this->storagePathFromUrl($settings['favicon_url']);

        Storage::disk('public')->assertExists($logoPath);
        Storage::disk('public')->assertExists($faviconPath);
        $this->assertDatabaseHas('school_website_settings', [
            'school_id' => $school->id,
            'website_name' => 'A Public School',
        ]);

        $publicResponse = $this->getJson('/api/public/schools/'.$school->code.'/website')
            ->assertOk()
            ->assertJsonPath('settings.website_name', 'A Public School')
            ->assertJsonPath('settings.logo_url', $settings['logo_url'])
            ->assertJsonPath('settings.contact_email', 'contact@example.test');
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));
    }

    public function test_default_public_school_is_the_first_active_school_and_ignores_inactive_schools(): void
    {
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        $schoolC = $this->createSchool('C');
        $schoolA->update(['is_active' => false]);
        $schoolC->delete();

        $this->getJson('/api/public/default-school')
            ->assertOk()
            ->assertJsonPath('school_code', $schoolB->code);
    }

    public function test_configured_default_public_school_takes_precedence_when_active(): void
    {
        $first = $this->createSchool('FIRST');
        $configured = $this->createSchool('PREFERRED');
        config()->set('services.public_default_school_code', $configured->code);

        $this->getJson('/api/public/default-school')
            ->assertOk()
            ->assertJsonPath('school_code', $configured->code);

        $configured->update(['is_active' => false]);
        $this->getJson('/api/public/default-school')
            ->assertOk()
            ->assertJsonPath('school_code', $first->code);
    }

    public function test_public_school_selector_lists_active_public_identity_only(): void
    {
        $activeA = $this->createSchool('PUBLIC-A');
        $activeB = $this->createSchool('PUBLIC-B');
        $inactive = $this->createSchool('PRIVATE');
        $inactive->update(['is_active' => false]);

        $this->getJson('/api/public/schools')
            ->assertOk()
            ->assertJsonCount(2, 'schools')
            ->assertJsonFragment(['school_code' => $activeA->code, 'website_name' => $activeA->name])
            ->assertJsonMissing(['school_code' => $inactive->code])
            ->assertJsonMissingPath('schools.0.email');
    }

    public function test_super_admin_can_manage_any_school_website_but_school_admin_cannot_use_that_route(): void
    {
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);
        $adminA = $this->createAdmin($schoolA, 'admin-a@example.test');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/schools/{$schoolB->id}/website-settings")
            ->assertOk()
            ->assertJsonPath('settings.school_id', $schoolB->id);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/schools/{$schoolB->id}/website-settings", [
                'website_name' => 'Managed by Super Admin',
                'meta_description' => 'A public description.',
                'instagram_url' => 'https://instagram.com/example-school',
                'font_family' => 'serif',
            ])
            ->assertOk()
            ->assertJsonPath('settings.website_name', 'Managed by Super Admin')
            ->assertJsonPath('settings.meta_description', 'A public description.')
            ->assertJsonPath('settings.instagram_url', 'https://instagram.com/example-school')
            ->assertJsonPath('settings.font_family', 'serif');

        $this->actingAs($adminA, 'sanctum')
            ->getJson("/api/schools/{$schoolB->id}/website-settings")
            ->assertForbidden();
    }

    public function test_website_settings_reject_invalid_colors_urls_and_uploads(): void
    {
        $school = $this->createSchool('A');
        $admin = $this->createAdmin($school, 'admin-a@example.test');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'website_name' => 'My school',
                'primary_color' => 'red',
                'facebook_url' => 'javascript:alert(1)',
                'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_color', 'facebook_url', 'logo']);
    }

    private function createSchool(string $suffix): School
    {
        return School::create([
            'name' => "School {$suffix}",
            'code' => "SCH-{$suffix}",
            'email' => "school-{$suffix}@example.test",
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

    private function storagePathFromUrl(string $url): string
    {
        return substr($url, strpos($url, '/storage/') + strlen('/storage/'));
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    }
}
