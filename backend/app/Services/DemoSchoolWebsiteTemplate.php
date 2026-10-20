<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DemoSchoolWebsiteTemplate
{
    private const ASSET_DIRECTORY = 'demo-school';

    /**
     * Initialize a new school only when it has no website settings yet.
     */
    public function initialize(School $school, bool $initializeMissing = false): bool
    {
        $existingSettings = $school->websiteSettings()->first();
        $needsSettings = $existingSettings === null;
        $needsContent = $existingSettings !== null && $existingSettings->content === null;
        $needsSliders = ! $school->homepageSliders()->exists();

        if ($needsSettings === false && ! $initializeMissing) {
            return false;
        }
        if (! $needsSettings && ! $needsContent && ! $needsSliders) {
            return false;
        }

        $paths = [];

        try {
            foreach ($this->assetNames() as $assetName) {
                $path = self::ASSET_DIRECTORY.'/'.$assetName;
                if (! Storage::disk('public')->exists($path)) {
                    $source = __DIR__.'/../../database/seeders/assets/'.$assetName;
                    if (! is_file($source)) {
                        throw new RuntimeException("Demo image asset {$assetName} is missing.");
                    }
                    $contents = file_get_contents($source);
                    if ($contents === false || ! Storage::disk('public')->put($path, $contents)) {
                        throw new RuntimeException("Unable to initialize demo image {$assetName}.");
                    }
                    $paths[] = $path;
                }
            }

            DB::transaction(function () use ($school, $needsSettings, $needsContent): void {
                $settings = $needsSettings
                    ? $school->websiteSettings()->create($this->settingsAttributes())
                    : $school->websiteSettings()->firstOrFail();

                if ($needsContent) {
                    $settings->update(['content' => $this->content()]);
                }

                if (! $school->homepageSliders()->exists()) {
                    foreach ($this->sliders() as $slider) {
                        $school->homepageSliders()->create($slider);
                    }
                }
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function assetNames(): array
    {
        return [
            'demo-school-building.svg',
            'demo-school-logo.svg',
            'demo-classroom.svg',
            'demo-library.svg',
            'demo-laboratory.svg',
            'demo-sports.svg',
            'demo-cultural-event.svg',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsAttributes(): array
    {
        return [
            'website_name' => 'Green Valley Model School',
            'tagline' => 'Learning Today, Leading Tomorrow',
            'logo_path' => self::ASSET_DIRECTORY.'/demo-school-logo.svg',
            'favicon_path' => self::ASSET_DIRECTORY.'/demo-school-logo.svg',
            'contact_email' => 'demo-contact@example.invalid',
            'phone' => '+880 1XXX-XXXXXX (DEMO)',
            'address' => 'Example Road, Dhaka, Bangladesh',
            'about' => 'Green Valley Model School is a sample learning community created to help your school launch a complete website. Replace this clearly marked demo content with your school’s approved information.',
            'primary_color' => '#236b52',
            'secondary_color' => '#f0f7e9',
            'footer_text' => 'Demo website content — please replace before publishing official school information.',
            'content' => $this->content(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        return [
            'status' => 'published',
            'welcome_message' => 'Welcome to Green Valley Model School. This sample welcome message is demo content and can be replaced in Website Settings.',
            'principal_message' => [
                'id' => 'demo-principal',
                'name' => 'Demo Principal',
                'designation' => 'Principal (Demo Profile)',
                'message' => 'We believe every learner can grow through curiosity, kindness, and consistent effort. This sample message is for demonstration only.',
                'image_path' => self::ASSET_DIRECTORY.'/demo-school-building.svg',
            ],
            'school_history' => 'Green Valley Model School is a fictional example established to demonstrate the school website template. Add your school’s verified history here.',
            'mission' => 'To provide an inclusive, supportive learning environment where every student can develop knowledge, character, and confidence.',
            'vision' => 'To inspire thoughtful, capable learners who contribute positively to their communities.',
            'facilities' => [
                'Bright, well-equipped classrooms',
                'Hands-on science laboratory',
                'Library and reading corner',
                'Outdoor sports and activity area',
            ],
            'academic_programs' => [
                'Early years and foundational learning (demo)',
                'Primary education (demo)',
                'Science, technology, and creative arts (demo)',
            ],
            'achievements' => [
                'Demo achievement: celebrating student curiosity and teamwork',
                'Demo achievement: annual reading and science fair',
                'Demo achievement: community arts and culture showcase',
            ],
            'statistics' => [
                ['label' => 'Learning spaces', 'value' => '18'],
                ['label' => 'Years of learning', 'value' => '12+'],
                ['label' => 'Student activities', 'value' => '20+'],
                ['label' => 'Community events', 'value' => '8'],
            ],
            'admission_information' => 'Demo admission information: contact the school office for current dates, eligibility, required documents, and application procedures. Replace this text with approved details before publishing.',
            'gallery' => [
                $this->galleryItem('building', 'Our School Building', 'A welcoming campus in a green setting.', 'demo-school-building.svg'),
                $this->galleryItem('classroom', 'Bright Classrooms', 'Spaces designed for discussion, discovery, and learning.', 'demo-classroom.svg'),
                $this->galleryItem('laboratory', 'Science Laboratory', 'A sample view of hands-on science learning.', 'demo-laboratory.svg'),
                $this->galleryItem('library', 'Library and Reading', 'A quiet place to explore books and ideas.', 'demo-library.svg'),
                $this->galleryItem('sports', 'Sports and Activities', 'Teamwork, movement, and healthy play.', 'demo-sports.svg'),
                $this->galleryItem('culture', 'Cultural Events', 'Celebrating creativity and community.', 'demo-cultural-event.svg'),
            ],
            'notices' => [
                $this->notice('admission-open', 'Admission Open (Demo Notice)', 'Sample notice: replace with approved admission dates and instructions.', '2026-11-01'),
                $this->notice('upcoming-examination', 'Upcoming Examination (Demo Notice)', 'Sample notice: confirm the examination schedule with the school office.', '2026-11-15'),
                $this->notice('annual-sports-day', 'Annual Sports Day (Demo Notice)', 'Sample notice: update with the official event date and arrangements.', '2026-12-05'),
                $this->notice('school-holiday', 'School Holiday Notice (Demo Notice)', 'Sample notice: replace with an authorized holiday announcement.', '2026-12-20'),
            ],
            'news' => [
                $this->news('science-fair', 'Science Fair (Demo Event)', 'A sample event entry celebrating experiments, questions, and discovery.', '2026-11-20'),
                $this->news('community-reading-day', 'Community Reading Day (Demo News)', 'A sample school story about sharing books and reading together.', '2026-12-02'),
            ],
            'staff' => [
                ['id' => 'demo-staff-one', 'name' => 'Demo Staff Member One', 'designation' => 'Head Teacher (Demo Profile)', 'bio' => 'Fictional website-only profile; not a school account.', 'image_path' => self::ASSET_DIRECTORY.'/demo-classroom.svg'],
                ['id' => 'demo-staff-two', 'name' => 'Demo Staff Member Two', 'designation' => 'Science Teacher (Demo Profile)', 'bio' => 'Fictional website-only profile; not a school account.', 'image_path' => self::ASSET_DIRECTORY.'/demo-laboratory.svg'],
                ['id' => 'demo-staff-three', 'name' => 'Demo Staff Member Three', 'designation' => 'Librarian (Demo Profile)', 'bio' => 'Fictional website-only profile; not a school account.', 'image_path' => self::ASSET_DIRECTORY.'/demo-library.svg'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function galleryItem(string $id, string $title, string $description, string $image): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'image_path' => self::ASSET_DIRECTORY.'/'.$image,
            'is_active' => true,
            'status' => 'published',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notice(string $id, string $title, string $description, string $date): array
    {
        return compact('id', 'title', 'description', 'date') + [
            'is_demo' => true,
            'status' => 'published',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function news(string $id, string $title, string $description, string $date): array
    {
        return compact('id', 'title', 'description', 'date') + [
            'is_demo' => true,
            'status' => 'published',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sliders(): array
    {
        return [
            [
                'title' => 'Welcome to Our School',
                'subtitle' => 'Learning Today, Leading Tomorrow',
                'description' => 'Discover a friendly place to learn, explore, and grow. Demo text — customize before sharing.',
                'image_path' => self::ASSET_DIRECTORY.'/demo-school-building.svg',
                'button_text' => 'Discover Our School',
                'button_url' => '#about',
                'display_order' => 1,
                'is_active' => true,
                'status' => 'published',
            ],
            [
                'title' => 'Quality Education for Every Student',
                'subtitle' => 'Curiosity opens the door to learning',
                'description' => 'Our sample classroom celebrates thoughtful teaching and active participation.',
                'image_path' => self::ASSET_DIRECTORY.'/demo-classroom.svg',
                'button_text' => 'Explore Learning',
                'button_url' => '#facilities',
                'display_order' => 2,
                'is_active' => true,
                'status' => 'published',
            ],
            [
                'title' => 'Building a Bright Future Together',
                'subtitle' => 'Every learner belongs',
                'description' => 'A demo invitation to celebrate teamwork, creativity, and a brighter future.',
                'image_path' => self::ASSET_DIRECTORY.'/demo-cultural-event.svg',
                'button_text' => 'Get in Touch',
                'button_url' => '#contact',
                'display_order' => 3,
                'is_active' => true,
                'status' => 'published',
            ],
        ];
    }
}
