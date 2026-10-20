<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolHomepageSlider;
use App\Models\User;
use App\Services\DemoSchoolWebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSchoolWebsiteTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_school_creation_initializes_demo_website_content_and_public_assets(): void
    {
        Storage::fake('public');
        $superAdmin = $this->createUser(null, 'super_admin', 'super-admin@example.test');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/schools', [
                'name' => 'New Test School',
                'code' => 'NEW-TEST',
                'admin_name' => 'School Admin',
                'admin_email' => 'new-admin@example.test',
                'admin_password' => 'password123',
            ])
            ->assertCreated();

        $school = School::query()->findOrFail($response->json('school.id'));
        $this->assertDatabaseHas('school_website_settings', [
            'school_id' => $school->id,
            'website_name' => 'Green Valley Model School',
        ]);
        $this->assertSame(3, $school->homepageSliders()->count());
        $this->assertSame(6, count($school->websiteSettings()->firstOrFail()->content['gallery']));
        Storage::disk('public')->assertExists('demo-school/demo-school-logo.svg');

        foreach ([
            'demo-school/demo-school-building.svg',
            'demo-school/demo-classroom.svg',
            'demo-school/demo-library.svg',
            'demo-school/demo-laboratory.svg',
            'demo-school/demo-sports.svg',
            'demo-school/demo-cultural-event.svg',
        ] as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->getJson("/api/public/schools/{$school->code}/website")
            ->assertOk()
            ->assertJsonPath('settings.website_name', 'Green Valley Model School')
            ->assertJsonPath('settings.contact_email', 'demo-contact@example.invalid')
            ->assertJsonPath('settings.content.welcome_message', 'Welcome to Green Valley Model School. This sample welcome message is demo content and can be replaced in Website Settings.')
            ->assertJsonPath('settings.content.gallery.0.title', 'Our School Building')
            ->assertJsonPath('settings.content.staff.0.name', 'Demo Staff Member One');

        $this->getJson("/api/public/schools/{$school->code}/sliders")
            ->assertOk()
            ->assertJsonCount(3, 'sliders')
            ->assertJsonPath('sliders.0.title', 'Welcome to Our School')
            ->assertJsonPath('sliders.0.status', 'published');
    }

    public function test_school_admin_can_edit_settings_content_and_replace_gallery_images_without_affecting_another_school(): void
    {
        Storage::fake('public');
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        app(DemoSchoolWebsiteTemplate::class)->initialize($schoolA);
        app(DemoSchoolWebsiteTemplate::class)->initialize($schoolB);
        $adminA = $this->createUser($schoolA, 'school_admin', 'school-a@example.test');
        $content = $schoolA->websiteSettings()->firstOrFail()->content;
        $content['welcome_message'] = 'Welcome text edited by School A.';
        $content['gallery'][0]['title'] = 'Our updated building';
        $content['academic_programs'] = ['Primary learning', 'Secondary learning'];
        $content['navigation'] = [
            ['id' => 'about-link', 'label' => 'About us', 'href' => '#about'],
            ['id' => 'programs-link', 'label' => 'Programs', 'href' => '#programs'],
        ];
        $content['visible_sections'] = ['about', 'programs', 'contact'];
        $content['section_order'] = ['about', 'welcome', 'statistics', 'mission', 'principal', 'history', 'facilities', 'programs', 'achievements', 'gallery', 'admissions', 'staff', 'notices', 'news', 'contact'];

        $this->actingAs($adminA, 'sanctum')
            ->post('/api/school/website-settings', [
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
                'gallery_images' => [
                    UploadedFile::fake()->createWithContent('new-building.png', $this->onePixelPng()),
                ],
                'principal_image' => UploadedFile::fake()->createWithContent('principal.png', $this->onePixelPng()),
                'staff_images' => [
                    UploadedFile::fake()->createWithContent('staff.png', $this->onePixelPng()),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.content.welcome_message', 'Welcome text edited by School A.')
            ->assertJsonPath('settings.content.gallery.0.title', 'Our updated building')
            ->assertJsonPath('settings.content.academic_programs.0', 'Primary learning')
            ->assertJsonCount(2, 'settings.content.navigation')
            ->assertJsonCount(3, 'settings.content.visible_sections');

        $updated = $schoolA->websiteSettings()->firstOrFail();
        $newPath = $updated->content['gallery'][0]['image_path'];
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertExists($updated->content['principal_message']['image_path']);
        Storage::disk('public')->assertExists($updated->content['staff'][0]['image_path']);
        $this->assertSame(
            'Welcome text edited by School A.',
            $this->getJson("/api/public/schools/{$schoolA->code}/website")->json('settings.content.welcome_message'),
        );
        $this->assertSame(
            'Welcome to Green Valley Model School. This sample welcome message is demo content and can be replaced in Website Settings.',
            $schoolB->websiteSettings()->firstOrFail()->content['welcome_message'],
        );

        $sliderCount = SchoolHomepageSlider::query()->where('school_id', $schoolA->id)->count();
        $this->assertFalse(app(DemoSchoolWebsiteTemplate::class)->initialize($schoolA->fresh()));
        $this->assertSame($sliderCount, $schoolA->homepageSliders()->count());
        $this->assertSame('Welcome text edited by School A.', $schoolA->websiteSettings()->firstOrFail()->content['welcome_message']);
    }

    public function test_manual_initialization_command_is_safe_to_run_more_than_once(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('MANUAL');

        $this->artisan('school:initialize-demo-website', ['school' => $school->code])
            ->expectsOutput("Demo website content initialized for {$school->name} ({$school->code}).")
            ->assertExitCode(0);

        $school->websiteSettings()->update(['website_name' => 'Customized school name']);
        $this->artisan('school:initialize-demo-website', ['school' => $school->code])
            ->expectsOutput('This school already has website template content. Existing settings were left unchanged.')
            ->assertExitCode(0);

        $this->assertSame('Customized school name', $school->websiteSettings()->value('website_name'));
        $this->assertSame(3, $school->homepageSliders()->count());
    }

    public function test_manual_initialization_fills_only_missing_content_for_an_existing_school(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('LEGACY');
        $school->websiteSettings()->create([
            'website_name' => 'Existing custom website',
            'tagline' => 'Already customized',
        ]);

        $this->artisan('school:initialize-demo-website', ['school' => $school->code])
            ->assertExitCode(0);

        $settings = $school->websiteSettings()->firstOrFail();
        $this->assertSame('Existing custom website', $settings->website_name);
        $this->assertSame('Already customized', $settings->tagline);
        $this->assertSame('published', $settings->content['status']);
        $this->assertSame(3, $school->homepageSliders()->count());
    }

    public function test_public_website_hides_draft_sections_notices_events_and_gallery_items(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('DRAFT');
        app(DemoSchoolWebsiteTemplate::class)->initialize($school);
        $admin = $this->createUser($school, 'school_admin', 'draft-admin@example.test');
        $content = $school->websiteSettings()->firstOrFail()->content;
        $content['status'] = 'draft';

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
            ])
            ->assertOk()
            ->assertJsonPath('settings.content.status', 'draft');

        $this->getJson("/api/public/schools/{$school->code}/website")
            ->assertOk()
            ->assertJsonPath('settings.content', null);

        $content['status'] = 'published';
        $content['gallery'][0]['status'] = 'draft';
        $content['notices'][0]['status'] = 'draft';
        $content['news'][0]['status'] = 'draft';
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'content' => json_encode($content, JSON_THROW_ON_ERROR),
            ])
            ->assertOk();

        $this->getJson("/api/public/schools/{$school->code}/website")
            ->assertOk()
            ->assertJsonCount(5, 'settings.content.gallery')
            ->assertJsonCount(3, 'settings.content.notices')
            ->assertJsonCount(1, 'settings.content.news');
    }

    public function test_gallery_settings_remain_school_scoped_and_invalid_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        app(DemoSchoolWebsiteTemplate::class)->initialize($schoolA);
        app(DemoSchoolWebsiteTemplate::class)->initialize($schoolB);
        $adminB = $this->createUser($schoolB, 'school_admin', 'school-b@example.test');
        $this->actingAs($adminB, 'sanctum')
            ->postJson('/api/school/website-settings', [
                'school_id' => $schoolA->id,
                'website_name' => 'School B website',
                'content' => json_encode(['unexpected' => 'not allowed'], JSON_THROW_ON_ERROR),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content.welcome_message');

        $this->actingAs($adminB, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post('/api/school/website-settings', [
                'school_id' => $schoolA->id,
                'website_name' => 'School B website',
                'content' => json_encode($schoolB->websiteSettings()->firstOrFail()->content, JSON_THROW_ON_ERROR),
                'gallery_images' => [
                    UploadedFile::fake()->create('script.svg', 12, 'image/svg+xml'),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gallery_images.0');

        $this->assertSame(
            'Welcome to Green Valley Model School. This sample welcome message is demo content and can be replaced in Website Settings.',
            $schoolA->websiteSettings()->firstOrFail()->content['welcome_message'],
        );
        $this->assertSame(
            'Welcome to Green Valley Model School. This sample welcome message is demo content and can be replaced in Website Settings.',
            $schoolB->websiteSettings()->firstOrFail()->content['welcome_message'],
        );
    }

    private function createSchool(string $suffix): School
    {
        return School::create([
            'name' => "School {$suffix}",
            'code' => "SCH-{$suffix}",
            'email' => "school-{$suffix}@example.test",
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

    private function onePixelPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    }
}
