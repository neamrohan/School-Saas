<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolWebsiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SchoolWebsiteSettingsController extends Controller
{
    private const WEBSITE_SECTIONS = [
        'about', 'welcome', 'statistics', 'mission', 'principal', 'history',
        'facilities', 'programs', 'achievements', 'gallery', 'admissions',
        'staff', 'notices', 'news', 'contact',
    ];

    private const DEFAULT_NAVIGATION = [
        ['id' => 'nav-about', 'label' => 'About', 'href' => '#about'],
        ['id' => 'nav-gallery', 'label' => 'Gallery', 'href' => '#gallery'],
        ['id' => 'nav-notices', 'label' => 'Notices', 'href' => '#notices'],
        ['id' => 'nav-contact', 'label' => 'Contact', 'href' => '#contact'],
    ];

    public function showForAdmin(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);

        return response()->json([
            'settings' => $this->present(
                $school,
                $this->settingsFor($school),
                $request,
            ),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);
        $validated = $request->validate([
            'website_name' => ['sometimes', 'required', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'about' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'primary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'youtube_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'instagram_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'font_family' => ['sometimes', 'required', 'in:system,serif,sans-serif'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'content' => ['sometimes', 'required', 'json', 'max:100000'],
            'logo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'favicon' => ['sometimes', 'file', 'mimes:ico,jpg,jpeg,png,webp', 'max:2048'],
            'principal_image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'gallery_images.*' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'staff_images.*' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $settings = $this->settingsFor($school);
        $oldPaths = [];
        $oldContentImagePaths = [];
        $newPaths = [];

        try {
            if (isset($validated['content'])) {
                $content = json_decode($validated['content'], true, 512, JSON_THROW_ON_ERROR);
                $content = $this->validateContent($content);
                $oldGallery = collect($settings->content['gallery'] ?? [])->keyBy('id');
                $oldStaff = collect($settings->content['staff'] ?? [])->keyBy('id');
                $content['principal_message']['image_path'] = $settings->content['principal_message']['image_path'] ?? null;
                unset($content['principal_message']['image_url']);

                if ($request->hasFile('principal_image')) {
                    $path = $this->storeContentImage($request->file('principal_image'), $school);
                    $newPaths[] = $path;
                    $oldContentImagePaths[] = $content['principal_message']['image_path'];
                    $content['principal_message']['image_path'] = $path;
                }

                foreach ($content['gallery'] as $index => &$item) {
                    $existing = $oldGallery->get($item['id']);
                    $item['image_path'] = $existing['image_path'] ?? null;
                    unset($item['image_url']);
                    $file = $request->file("gallery_images.{$index}");

                    if ($file) {
                        $path = $file->store("school-websites/{$school->id}/gallery", 'public');
                        if (! $path) {
                            throw new RuntimeException('Unable to store the gallery image.');
                        }
                        $newPaths[] = $path;
                        if (! empty($item['image_path'])) {
                            $oldContentImagePaths[] = $item['image_path'];
                        }
                        $item['image_path'] = $path;
                    } elseif (! $item['image_path']) {
                        throw ValidationException::withMessages([
                            "content.gallery.{$index}.image" => ['Choose an image for each gallery item.'],
                        ]);
                    }
                }
                unset($item);
                foreach ($content['staff'] as $index => &$member) {
                    $oldImagePath = $oldStaff->get($member['id'])['image_path'] ?? null;
                    $member['image_path'] = $oldImagePath;
                    unset($member['image_url']);
                    $file = $request->file("staff_images.{$index}");
                    if ($file) {
                        $path = $this->storeContentImage($file, $school);
                        $newPaths[] = $path;
                        $oldContentImagePaths[] = $oldImagePath;
                        $member['image_path'] = $path;
                    }
                }
                unset($member);

                foreach ($oldGallery as $id => $item) {
                    if (! collect($content['gallery'])->contains('id', $id)) {
                        $oldContentImagePaths[] = $item['image_path'] ?? null;
                    }
                }
                foreach ($oldStaff as $id => $member) {
                    if (! collect($content['staff'])->contains('id', $id)) {
                        $oldContentImagePaths[] = $member['image_path'] ?? null;
                    }
                }
                $validated['content'] = $content;
            }

            foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $field => $pathField) {
                if (! $request->hasFile($field)) {
                    continue;
                }

                $path = $this->storeImage($request->file($field), $school, $field);
                $newPaths[] = $path;
                $oldPaths[] = $settings->{$pathField};
                $validated[$pathField] = $path;
            }

            $settings = DB::transaction(function () use ($settings, $validated): SchoolWebsiteSetting {
                $settings->update(collect($validated)->except(['logo', 'favicon'])->all());

                return $settings->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteUnreferencedGalleryFiles($school, $newPaths);

            throw $exception;
        }

        Storage::disk('public')->delete(array_filter($oldPaths));
        $this->deleteUnreferencedGalleryFiles($school, $oldContentImagePaths);

        return response()->json([
            'message' => 'Website settings saved successfully.',
            'settings' => $this->present($school, $settings, $request),
        ])->header('Cache-Control', 'no-store');
    }

    public function showPublic(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();

        return response()->json([
            'settings' => $this->present($school, $this->settingsFor($school), $request, true),
        ])->header('Cache-Control', 'no-store');
    }

    public function defaultPublicSchool(): JsonResponse
    {
        $configuredCode = config('services.public_default_school_code');
        $activeSchools = School::query()->where('is_active', true);
        $schoolCode = $configuredCode
            ? (clone $activeSchools)->where('code', $configuredCode)->value('code')
            : null;
        $schoolCode ??= $activeSchools
            ->orderBy('id')
            ->value('code');

        abort_if($schoolCode === null, 404, 'No active school website is available.');

        return response()->json(['school_code' => $schoolCode])
            ->header('Cache-Control', 'no-store');
    }

    public function publicSchools(Request $request): JsonResponse
    {
        $schools = School::query()
            ->where('is_active', true)
            ->with('websiteSettings:id,school_id,website_name,logo_path')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'logo'])
            ->map(fn (School $school): array => [
                'school_code' => $school->code,
                'website_name' => $school->websiteSettings?->website_name ?? $school->name,
                'logo_url' => $this->assetUrl($school->websiteSettings?->logo_path, $request) ?? $school->logo,
            ]);

        return response()->json(['schools' => $schools])
            ->header('Cache-Control', 'no-store');
    }

    private function authorizedSchool(Request $request): School
    {
        $user = $request->user();
        if ($user?->role === 'super_admin') {
            $schoolId = $request->route('school');
            $school = $schoolId instanceof School ? $schoolId : School::query()->find($schoolId);
            abort_unless($school instanceof School, 404, 'Choose a school to manage.');

            return $school;
        }

        abort_unless($user?->role === 'school_admin', 403);

        $school = $user->school;
        abort_unless($school instanceof School, 403, 'School access denied.');

        return $school;
    }

    private function settingsFor(School $school): SchoolWebsiteSetting
    {
        return $school->websiteSettings()->firstOrCreate([], [
            'website_name' => $school->name,
            'contact_email' => $school->email,
            'phone' => $school->phone,
            'address' => $school->address,
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function present(School $school, SchoolWebsiteSetting $settings, Request $request, bool $public = false): array
    {
        $content = $settings->content;
        if ($public && ($content['status'] ?? 'draft') !== 'published') {
            $content = null;
        }
        if ($content) {
            $content['academic_programs'] ??= [];
            $content['navigation'] ??= self::DEFAULT_NAVIGATION;
            $content['visible_sections'] ??= self::WEBSITE_SECTIONS;
            $sectionOrder = $content['section_order'] ?? self::WEBSITE_SECTIONS;
            $middleSections = array_values(array_diff(
                array_intersect($sectionOrder, self::WEBSITE_SECTIONS),
                ['about', 'contact'],
            ));
            $content['section_order'] = array_merge(
                ['about'],
                array_values(array_unique(array_merge(
                    $middleSections,
                    array_diff(self::WEBSITE_SECTIONS, ['about', 'contact'], $middleSections),
                ))),
                ['contact'],
            );
            $content['staff'] = array_map(
                fn (array $member): array => [...$member, 'status' => $member['status'] ?? 'published'],
                $content['staff'] ?? [],
            );
            if ($public) {
                $visibleSections = $content['visible_sections'];
                $content['navigation'] = array_values(array_filter(
                    $content['navigation'],
                    fn (array $item): bool => in_array(ltrim($item['href'], '#'), $visibleSections, true),
                ));
                $content['gallery'] = array_values(array_filter(
                    $content['gallery'] ?? [],
                    fn (array $item): bool => ($item['is_active'] ?? false) && ($item['status'] ?? 'draft') === 'published',
                ));
                $content['notices'] = array_values(array_filter(
                    $content['notices'] ?? [],
                    fn (array $item): bool => ($item['status'] ?? 'draft') === 'published',
                ));
                $content['news'] = array_values(array_filter(
                    $content['news'] ?? [],
                    fn (array $item): bool => ($item['status'] ?? 'draft') === 'published',
                ));
                $content['staff'] = array_values(array_filter(
                    $content['staff'],
                    fn (array $member): bool => ($member['status'] ?? 'draft') === 'published',
                ));
            }
            $content['gallery'] = array_map(function (array $item) use ($request): array {
                $imagePath = $item['image_path'] ?? null;
                unset($item['image_path']);

                return [
                    ...$item,
                    'image_url' => $this->assetUrl($imagePath, $request),
                ];
            }, $content['gallery'] ?? []);
            $principal = $content['principal_message'] ?? [];
            $principal['image_url'] = $this->assetUrl($principal['image_path'] ?? null, $request);
            unset($principal['image_path']);
            $content['principal_message'] = $principal;
            $content['staff'] = array_map(function (array $member) use ($request): array {
                $imagePath = $member['image_path'] ?? null;
                unset($member['image_path']);

                return [...$member, 'image_url' => $this->assetUrl($imagePath, $request)];
            }, $content['staff'] ?? []);
        }

        return [
            'id' => $settings->id,
            'school_id' => $settings->school_id,
            'school_code' => $school->code,
            'website_name' => $settings->website_name,
            'meta_description' => $settings->meta_description,
            'tagline' => $settings->tagline,
            'logo_url' => $this->assetUrl($settings->logo_path, $request) ?? $school->logo,
            'favicon_url' => $this->assetUrl($settings->favicon_path, $request),
            'contact_email' => $settings->contact_email,
            'phone' => $settings->phone,
            'address' => $settings->address,
            'about' => $settings->about,
            'primary_color' => $settings->primary_color,
            'secondary_color' => $settings->secondary_color,
            'facebook_url' => $settings->facebook_url,
            'youtube_url' => $settings->youtube_url,
            'instagram_url' => $settings->instagram_url,
            'font_family' => $settings->font_family,
            'footer_text' => $settings->footer_text,
            'content' => $content,
            'updated_at' => $settings->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateContent(mixed $content): array
    {
        $validator = Validator::make(['content' => $content], [
            'content' => ['required', 'array'],
            'content.status' => ['required', 'in:draft,published'],
            'content.welcome_message' => ['required', 'string', 'max:5000'],
            'content.principal_message' => ['required', 'array'],
            'content.principal_message.id' => ['required', 'string', 'max:100'],
            'content.principal_message.name' => ['required', 'string', 'max:255'],
            'content.principal_message.designation' => ['required', 'string', 'max:255'],
            'content.principal_message.message' => ['required', 'string', 'max:5000'],
            'content.school_history' => ['required', 'string', 'max:10000'],
            'content.mission' => ['required', 'string', 'max:5000'],
            'content.vision' => ['required', 'string', 'max:5000'],
            'content.facilities' => ['required', 'array', 'max:30'],
            'content.facilities.*' => ['required', 'string', 'max:255'],
            'content.academic_programs' => ['sometimes', 'array', 'max:30'],
            'content.academic_programs.*' => ['required', 'string', 'max:255'],
            'content.achievements' => ['required', 'array', 'max:30'],
            'content.achievements.*' => ['required', 'string', 'max:500'],
            'content.statistics' => ['required', 'array', 'max:20'],
            'content.statistics.*' => ['required', 'array'],
            'content.statistics.*.label' => ['required', 'string', 'max:100'],
            'content.statistics.*.value' => ['required', 'string', 'max:100'],
            'content.admission_information' => ['required', 'string', 'max:5000'],
            'content.gallery' => ['required', 'array', 'max:30'],
            'content.gallery.*' => ['required', 'array'],
            'content.gallery.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'content.gallery.*.title' => ['required', 'string', 'max:255'],
            'content.gallery.*.description' => ['nullable', 'string', 'max:2000'],
            'content.gallery.*.is_active' => ['required', 'boolean'],
            'content.gallery.*.status' => ['required', 'in:draft,published'],
            'content.notices' => ['required', 'array', 'max:50'],
            'content.notices.*' => ['required', 'array'],
            'content.notices.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'content.notices.*.title' => ['required', 'string', 'max:255'],
            'content.notices.*.description' => ['required', 'string', 'max:3000'],
            'content.notices.*.date' => ['nullable', 'date'],
            'content.notices.*.status' => ['required', 'in:draft,published'],
            'content.notices.*.is_demo' => ['required', 'boolean'],
            'content.news' => ['required', 'array', 'max:50'],
            'content.news.*' => ['required', 'array'],
            'content.news.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'content.news.*.title' => ['required', 'string', 'max:255'],
            'content.news.*.description' => ['required', 'string', 'max:3000'],
            'content.news.*.date' => ['nullable', 'date'],
            'content.news.*.status' => ['required', 'in:draft,published'],
            'content.news.*.is_demo' => ['required', 'boolean'],
            'content.staff' => ['required', 'array', 'max:20'],
            'content.staff.*' => ['required', 'array'],
            'content.staff.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'content.staff.*.name' => ['required', 'string', 'max:255'],
            'content.staff.*.designation' => ['required', 'string', 'max:255'],
            'content.staff.*.bio' => ['required', 'string', 'max:1000'],
            'content.staff.*.status' => ['sometimes', 'in:draft,published'],
            'content.navigation' => ['sometimes', 'array', 'max:12'],
            'content.navigation.*' => ['required', 'array'],
            'content.navigation.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'content.navigation.*.label' => ['required', 'string', 'max:50'],
            'content.navigation.*.href' => ['required', 'regex:/^#[A-Za-z][A-Za-z0-9_-]*$/'],
            'content.visible_sections' => ['sometimes', 'array', 'max:15'],
            'content.visible_sections.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::WEBSITE_SECTIONS)],
            'content.section_order' => ['sometimes', 'array', 'max:15'],
            'content.section_order.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::WEBSITE_SECTIONS)],
        ]);

        $validated = $validator->validate()['content'];
        $validated['principal_message'] = Arr::only($validated['principal_message'], [
            'id',
            'name',
            'designation',
            'message',
            'image_url',
        ]);
        $validated['statistics'] = array_map(
            fn (array $item): array => Arr::only($item, ['label', 'value']),
            $validated['statistics'],
        );

        foreach (['gallery', 'notices', 'news', 'staff'] as $key) {
            $allowed = match ($key) {
                'gallery' => ['id', 'title', 'description', 'is_active', 'status', 'image_url'],
                'notices', 'news' => ['id', 'title', 'description', 'date', 'status', 'is_demo'],
                'staff' => ['id', 'name', 'designation', 'bio', 'image_url', 'status'],
            };
            $validated[$key] = array_map(
                fn (array $item): array => Arr::only(
                    $key === 'staff' ? [...$item, 'status' => $item['status'] ?? 'published'] : $item,
                    $allowed,
                ),
                $validated[$key],
            );
        }

        return Arr::only($validated, [
            'status',
            'welcome_message',
            'principal_message',
            'school_history',
            'mission',
            'vision',
            'facilities',
            'achievements',
            'statistics',
            'admission_information',
            'academic_programs',
            'gallery',
            'notices',
            'news',
            'staff',
            'navigation',
            'visible_sections',
            'section_order',
        ]);
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function deleteUnreferencedGalleryFiles(School $school, array $paths): void
    {
        $currentContent = $school->websiteSettings()->first()?->content ?? [];
        $contentPaths = collect($currentContent['gallery'] ?? [])->pluck('image_path')
            ->merge([$currentContent['principal_message']['image_path'] ?? null])
            ->merge(collect($currentContent['staff'] ?? [])->pluck('image_path'));
        $sliderPaths = $school->homepageSliders()
            ->get(['image_path', 'mobile_image_path'])
            ->flatMap(fn ($slider) => [$slider->image_path, $slider->mobile_image_path]);

        foreach (array_unique(array_filter($paths)) as $path) {
            if ($contentPaths->contains($path) || $sliderPaths->contains($path)) {
                continue;
            }
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Unable to delete an unreferenced demo gallery image.', ['path' => $path]);
            }
        }
    }

    private function assetUrl(?string $path, Request $request): ?string
    {
        if (! $path) {
            return null;
        }

        if (config('filesystems.disks.public.driver') === 'local') {
            return rtrim($request->getSchemeAndHttpHost(), '/').'/storage/'.ltrim($path, '/');
        }

        return Storage::disk('public')->url($path);
    }

    private function storeImage(UploadedFile $file, School $school, string $kind): string
    {
        $path = $file->store("school-websites/{$school->id}", 'public');

        if (! $path) {
            throw new RuntimeException("Unable to store the school website {$kind}.");
        }

        return $path;
    }

    private function storeContentImage(UploadedFile $file, School $school): string
    {
        $path = $file->store("school-websites/{$school->id}/content", 'public');
        if (! $path) {
            throw new RuntimeException('Unable to store the website content image.');
        }

        return $path;
    }
}
