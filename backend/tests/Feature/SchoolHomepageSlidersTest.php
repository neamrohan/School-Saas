<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolHomepageSlider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolHomepageSlidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_create_update_and_delete_only_its_own_sliders(): void
    {
        Storage::fake('public');
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        $adminA = $this->createAdmin($schoolA, 'admin-a@example.test');
        $adminB = $this->createAdmin($schoolB, 'admin-b@example.test');

        $created = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/school/sliders', [
                'school_id' => $schoolB->id,
                'title' => 'Welcome',
                'display_order' => 0,
                'is_active' => true,
                'status' => 'published',
                'image' => UploadedFile::fake()->createWithContent('desktop.png', $this->onePixelPng()),
            ])
            ->assertCreated()
            ->assertJsonPath('slider.school_id', $schoolA->id)
            ->assertJsonPath('slider.title', 'Welcome');

        $sliderId = $created->json('slider.id');
        $path = SchoolHomepageSlider::query()->findOrFail($sliderId)->image_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($adminB, 'sanctum')
            ->getJson('/api/school/sliders')
            ->assertOk()
            ->assertJsonCount(0, 'sliders');
        $this->actingAs($adminB, 'sanctum')
            ->getJson("/api/school/sliders/{$sliderId}")
            ->assertNotFound();
        $this->actingAs($adminB, 'sanctum')
            ->putJson("/api/school/sliders/{$sliderId}", ['title' => 'Hijacked'])
            ->assertNotFound();
        $this->actingAs($adminB, 'sanctum')
            ->deleteJson("/api/school/sliders/{$sliderId}")
            ->assertNotFound();

        $this->actingAs($adminA, 'sanctum')
            ->putJson("/api/school/sliders/{$sliderId}", [
                'title' => 'Updated welcome',
                'status' => 'draft',
                'button_url' => '#about',
            ])
            ->assertOk()
            ->assertJsonPath('slider.title', 'Updated welcome')
            ->assertJsonPath('slider.status', 'draft')
            ->assertJsonPath('slider.button_url', '#about');

        $this->actingAs($adminA, 'sanctum')
            ->deleteJson("/api/school/sliders/{$sliderId}")
            ->assertOk();

        $this->assertDatabaseMissing('school_homepage_sliders', ['id' => $sliderId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_public_sliders_show_only_active_published_records_in_saved_order(): void
    {
        $school = $this->createSchool('A');
        $first = $this->createSlider($school, 'First', 20, true, 'published');
        $second = $this->createSlider($school, 'Second', 10, true, 'published');
        $draft = $this->createSlider($school, 'Draft', 1, true, 'draft');
        $inactive = $this->createSlider($school, 'Inactive', 2, false, 'published');
        $third = $this->createSlider($school, 'Third', 30, true, 'draft');
        $fourth = $this->createSlider($school, 'Fourth', 40, false, 'draft');

        $this->getJson("/api/public/schools/{$school->code}/sliders")
            ->assertOk()
            ->assertJsonCount(2, 'sliders')
            ->assertJsonPath('sliders.0.id', $second->id)
            ->assertJsonPath('sliders.1.id', $first->id);
    }

    public function test_super_admin_can_manage_a_selected_schools_sliders(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('ADMIN-MANAGED');
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/schools/{$school->id}/sliders", [
                'title' => 'Super Admin banner',
                'display_order' => 0,
                'is_active' => true,
                'status' => 'published',
                'image' => UploadedFile::fake()->createWithContent('banner.png', $this->onePixelPng()),
            ])
            ->assertCreated()
            ->assertJsonPath('slider.school_id', $school->id);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/schools/{$school->id}/sliders")
            ->assertOk()
            ->assertJsonCount(1, 'sliders');
    }

    public function test_reordering_persists_and_returns_sliders_in_the_saved_order(): void
    {
        $school = $this->createSchool('REORDER');
        $admin = $this->createAdmin($school, 'admin-reorder@example.test');
        $first = $this->createSlider($school, 'First', 20, true, 'published');
        $second = $this->createSlider($school, 'Second', 10, true, 'published');
        $draft = $this->createSlider($school, 'Draft', 1, true, 'draft');
        $inactive = $this->createSlider($school, 'Inactive', 2, false, 'published');
        $third = $this->createSlider($school, 'Third', 30, true, 'draft');
        $fourth = $this->createSlider($school, 'Fourth', 40, false, 'draft');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/school/sliders/reorder', [
                'slider_ids' => [$first->id, $second->id, $draft->id, $inactive->id, $third->id, $fourth->id],
            ])
            ->assertOk()
            ->assertJsonPath('sliders.0.id', $first->id)
            ->assertJsonPath('sliders.0.display_order', 1)
            ->assertJsonPath('sliders.1.id', $second->id)
            ->assertJsonPath('sliders.1.display_order', 2);

        $this->assertSame(1, $first->fresh()->display_order);
        $this->assertSame(2, $second->fresh()->display_order);
    }

    public function test_rejects_unsafe_slider_uploads_and_incomplete_cross_school_reordering(): void
    {
        Storage::fake('public');
        $schoolA = $this->createSchool('A');
        $schoolB = $this->createSchool('B');
        $admin = $this->createAdmin($schoolA, 'admin-a@example.test');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/school/sliders', [
                'title' => 'Unsafe',
                'image' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('school_homepage_sliders', 0);

        $ownedSlider = $this->createSlider($schoolA, 'Owned', 0, true, 'draft');
        $foreignSlider = $this->createSlider($schoolB, 'Other school', 0, true, 'draft');
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/school/sliders/reorder', [
                'slider_ids' => [$ownedSlider->id, $foreignSlider->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slider_ids');
    }

    public function test_replaced_image_is_kept_while_still_referenced_by_another_slider(): void
    {
        Storage::fake('public');
        $school = $this->createSchool('A');
        $admin = $this->createAdmin($school, 'admin-a@example.test');
        $sharedPath = "school-sliders/{$school->id}/shared.png";
        Storage::disk('public')->put($sharedPath, 'shared image');
        $slider = $this->createSlider($school, 'Original', 0, true, 'draft', $sharedPath);
        $other = $this->createSlider($school, 'Still using image', 1, true, 'draft', $sharedPath);

        $payload = [
            'title' => 'Replaced',
            'image' => UploadedFile::fake()->createWithContent('new.png', $this->onePixelPng()),
            '_method' => 'PUT',
        ];
        $this->actingAs($admin, 'sanctum')
            ->post("/api/school/sliders/{$slider->id}", $payload)
            ->assertOk()
            ->assertJsonPath('slider.title', 'Replaced');
        Storage::disk('public')->assertExists($sharedPath);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/school/sliders/{$other->id}")->assertOk();
        Storage::disk('public')->assertMissing($sharedPath);
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

    private function createSlider(
        School $school,
        string $title,
        int $order,
        bool $active,
        string $status,
        string $imagePath = 'school-sliders/test/slide.jpg',
    ): SchoolHomepageSlider {
        return $school->homepageSliders()->create([
            'title' => $title,
            'image_path' => $imagePath,
            'display_order' => $order,
            'is_active' => $active,
            'status' => $status,
        ]);
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
    }
}
