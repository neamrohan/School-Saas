<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Services\DemoSchoolWebsiteTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    /**
     * Display a listing of schools.
     */
    public function index()
    {
        $schools = School::latest()->get();

        return response()->json([
            'schools' => $schools,
        ]);
    }

    /**
     * Display schools that have been removed.
     */
    public function deleted()
    {
        return response()->json([
            'schools' => School::onlyTrashed()->latest('deleted_at')->get(),
        ]);
    }

    /**
     * Store a newly created school.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:schools,code'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        [$school, $admin] = DB::transaction(function () use ($validated) {
            $school = School::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'logo' => $validated['logo'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $admin = User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'school_id' => $school->id,
                'role' => 'school_admin',
            ]);

            app(DemoSchoolWebsiteTemplate::class)->initialize($school);

            return [$school, $admin->makeHidden(['password', 'remember_token'])];
        });

        return response()->json([
            'message' => 'School created successfully.',
            'school' => $school,
            'admin_user' => $admin,
        ], 201);
    }

    /**
     * Display the specified school.
     */
    public function show(School $school)
    {
        return response()->json([
            'school' => $school->load('users'),
        ]);
    }

    /**
     * Update the specified school.
     */
    public function update(Request $request, School $school)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('schools', 'code')->ignore($school->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'logo' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $school->update($validated);

        return response()->json([
            'message' => 'School updated successfully.',
            'school' => $school->fresh(),
        ]);
    }

    /**
     * Remove the specified school.
     */
    public function destroy(School $school)
    {
        DB::transaction(function () use ($school): void {
            $users = $school->users()->where('role', '!=', 'super_admin')->get();

            foreach ($users as $user) {
                $user->tokens()->delete();
            }

            if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
                DB::table(config('session.table', 'sessions'))
                    ->whereIn('user_id', $users->modelKeys())
                    ->delete();
            }

            $school->delete();
        });

        return response()->json([
            'message' => 'School removed successfully.',
        ]);
    }

    /**
     * Restore a removed school.
     */
    public function restore(int $school)
    {
        $school = School::onlyTrashed()->findOrFail($school);
        $school->restore();

        return response()->json([
            'message' => 'School restored successfully.',
            'school' => $school->fresh(),
        ]);
    }
}
