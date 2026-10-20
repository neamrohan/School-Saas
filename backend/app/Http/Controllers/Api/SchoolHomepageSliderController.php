<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolHomepageSlider;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SchoolHomepageSliderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);

        return response()->json([
            'sliders' => $this->presentMany(
                $school->homepageSliders()->orderBy('display_order')->orderBy('id')->get(),
                $request,
            ),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);
        $validated = $request->validate($this->rules(true));
        $paths = [];

        try {
            $paths['image_path'] = $this->storeImage($request->file('image'), $school);
            if ($request->hasFile('mobile_image')) {
                $paths['mobile_image_path'] = $this->storeImage($request->file('mobile_image'), $school);
            }

            $slider = DB::transaction(function () use ($school, $validated, $paths): SchoolHomepageSlider {
                unset($validated['image'], $validated['mobile_image']);

                return $school->homepageSliders()->create([
                    ...$validated,
                    ...$paths,
                ]);
            });
        } catch (Throwable $exception) {
            $this->deleteUnreferencedFiles(array_values($paths));
            throw $exception;
        }

        return response()->json([
            'message' => 'Homepage slider created successfully.',
            'slider' => $this->present($slider, $request),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $slider): JsonResponse
    {
        $school = $this->authorizedSchool($request);

        return response()->json([
            'slider' => $this->present($this->findForSchool($school, $slider), $request),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $slider): JsonResponse
    {
        $school = $this->authorizedSchool($request);
        $record = $this->findForSchool($school, $slider);
        $validated = $request->validate($this->rules(false));
        $oldPaths = [];
        $newPaths = [];

        try {
            foreach (['image' => 'image_path', 'mobile_image' => 'mobile_image_path'] as $field => $pathField) {
                if (! $request->hasFile($field)) {
                    continue;
                }

                $newPath = $this->storeImage($request->file($field), $school);
                $newPaths[] = $newPath;
                $oldPaths[] = $record->{$pathField};
                $validated[$pathField] = $newPath;
            }

            $record = DB::transaction(function () use ($record, $validated): SchoolHomepageSlider {
                $record->update(collect($validated)->except(['image', 'mobile_image'])->all());

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteUnreferencedFiles($newPaths);
            throw $exception;
        }

        $this->deleteUnreferencedFiles($oldPaths);

        return response()->json([
            'message' => 'Homepage slider updated successfully.',
            'slider' => $this->present($record, $request),
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $slider): JsonResponse
    {
        $school = $this->authorizedSchool($request);
        $record = $this->findForSchool($school, $slider);
        $paths = array_filter([$record->image_path, $record->mobile_image_path]);

        DB::transaction(fn () => $record->delete());
        $this->deleteUnreferencedFiles($paths);

        return response()->json([
            'message' => 'Homepage slider deleted successfully.',
        ])->header('Cache-Control', 'no-store');
    }

    public function reorder(Request $request): JsonResponse
    {
        $school = $this->authorizedSchool($request);
        $validated = $request->validate([
            'slider_ids' => ['required', 'array'],
            'slider_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $sliders = $school->homepageSliders()->orderBy('id')->get();
        $submittedIds = array_map('intval', $validated['slider_ids']);
        sort($submittedIds, SORT_NUMERIC);
        $ownedIds = array_map('intval', $sliders->modelKeys());
        sort($ownedIds, SORT_NUMERIC);

        if ($submittedIds !== $ownedIds) {
            throw ValidationException::withMessages([
                'slider_ids' => ['Provide every slider belonging to your school exactly once.'],
            ]);
        }

        DB::transaction(function () use ($validated, $school): void {
            foreach ($validated['slider_ids'] as $position => $id) {
                $school->homepageSliders()->whereKey($id)->update([
                    'display_order' => $position + 1,
                ]);
            }
        });

        return response()->json([
            'message' => 'Homepage slider order saved successfully.',
            'sliders' => $this->presentMany(
                $school->homepageSliders()->orderBy('display_order')->orderBy('id')->get(),
                $request,
            ),
        ])->header('Cache-Control', 'no-store');
    }

    public function publicForSchool(Request $request, string $schoolCode): JsonResponse
    {
        $school = School::query()->where('code', $schoolCode)->where('is_active', true)->firstOrFail();
        $sliders = $school->homepageSliders()
            ->where('is_active', true)
            ->where('status', 'published')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'sliders' => $this->presentMany($sliders, $request),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    private function rules(bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'image' => [$creating ? 'required' : 'sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'mobile_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'button_text' => ['sometimes', 'nullable', 'string', 'max:100'],
            'button_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_string($value)) {
                        $fail('The button URL must be an HTTP(S) URL or a valid on-page anchor.');

                        return;
                    }
                    if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/D', $value)
                        || (filter_var($value, FILTER_VALIDATE_URL)
                            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true))) {
                        return;
                    }

                    $fail('The button URL must be an HTTP(S) URL or a valid on-page anchor.');
                },
            ],
            'display_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'status' => ['sometimes', 'required', 'in:draft,published'],
        ];
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

    private function findForSchool(School $school, int $slider): SchoolHomepageSlider
    {
        return $school->homepageSliders()->findOrFail($slider);
    }

    private function storeImage(UploadedFile $file, School $school): string
    {
        $path = $file->store("school-sliders/{$school->id}", 'public');

        if (! $path) {
            throw new RuntimeException('Unable to store the homepage slider image.');
        }

        return $path;
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function deleteUnreferencedFiles(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (SchoolHomepageSlider::query()
                ->where('image_path', $path)
                ->orWhere('mobile_image_path', $path)
                ->exists()) {
                continue;
            }

            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Unable to delete an unreferenced homepage slider image.', [
                    'path' => $path,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, SchoolHomepageSlider>  $sliders
     * @return array<int, array<string, bool|int|string|null>>
     */
    private function presentMany(Collection $sliders, Request $request): array
    {
        return $sliders->map(fn (SchoolHomepageSlider $slider): array => $this->present($slider, $request))->all();
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function present(SchoolHomepageSlider $slider, Request $request): array
    {
        return [
            'id' => $slider->id,
            'school_id' => $slider->school_id,
            'title' => $slider->title,
            'subtitle' => $slider->subtitle,
            'description' => $slider->description,
            'image_url' => $this->assetUrl($slider->image_path, $request),
            'mobile_image_url' => $this->assetUrl($slider->mobile_image_path, $request),
            'button_text' => $slider->button_text,
            'button_url' => $slider->button_url,
            'display_order' => $slider->display_order,
            'is_active' => $slider->is_active,
            'status' => $slider->status,
            'created_at' => $slider->created_at?->toISOString(),
            'updated_at' => $slider->updated_at?->toISOString(),
        ];
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
}
