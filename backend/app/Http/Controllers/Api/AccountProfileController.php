<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->schoolUser($request->user());

        return response()->json([
            'user' => $user->load('school'),
            'teacher' => $user->role === 'teacher'
                ? $user->teacher()->with(['assignments.class', 'assignments.section', 'assignments.subject'])->firstOrFail()
                : null,
            'student' => $user->role === 'student'
                ? $user->student()->with(['school', 'class', 'section', 'academicYear'])->firstOrFail()
                : null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->schoolUser($request->user());
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->update(['name' => $validated['name']]);
        }

        if ($user->role === 'teacher') {
            $teacher = $user->teacher()->firstOrFail();
            $profileUpdates = array_intersect_key($validated, array_flip(['phone']));
            $this->storeProfilePhoto($request, $teacher, $profileUpdates);
            $teacher->update($profileUpdates);
        } else {
            $student = $user->student()->firstOrFail();
            $profileUpdates = array_intersect_key($validated, array_flip(['phone', 'address']));
            $this->storeProfilePhoto($request, $student, $profileUpdates);
            $student->update($profileUpdates);
        }

        return $this->show($request);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->schoolUser($request->user());
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    private function schoolUser(User $user): User
    {
        abort_unless(in_array($user->role, ['teacher', 'student'], true) && $user->school_id, 403);

        return $user;
    }

    private function storeProfilePhoto(Request $request, object $profile, array &$updates): void
    {
        if (! $request->hasFile('profile_photo')) {
            return;
        }

        $path = $request->file('profile_photo')->store('profile-photos', 'public');
        if ($profile->profile_photo_path) {
            Storage::disk('public')->delete($profile->profile_photo_path);
        }
        $updates['profile_photo_path'] = $path;
    }
}
