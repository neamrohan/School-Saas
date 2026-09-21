<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $users = User::with('school')
            ->where('school_id', $user->school_id)
            ->latest()
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'required',
                Rule::in(['teacher', 'student', 'parent']),
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'school_id' => $admin->school_id,
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->load('school'),
        ], 201);
    }

    public function show(Request $request, User $user)
    {
        $this->checkSchoolAccess($request, $user);

        return response()->json([
            'user' => $user->load('school'),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->checkSchoolAccess($request, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => [
                'sometimes',
                'required',
                Rule::in(['teacher', 'student', 'parent']),
            ],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->fresh()->load('school'),
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->checkSchoolAccess($request, $user);

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    private function checkSchoolAccess(Request $request, User $user): void
    {
        if ($user->school_id !== $request->user()->school_id) {
            abort(response()->json([
                'message' => 'Unauthorized school access.',
            ], 403));
        }
    }
}