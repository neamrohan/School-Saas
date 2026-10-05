<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'role' => ['sometimes', 'required', Rule::in(['super_admin', 'school_admin', 'teacher', 'student', 'parent'])],
        ]);

        $user = User::with('school')
            ->where(function ($query) use ($credentials): void {
                $query->where('email', $credentials['email'])
                    ->orWhere('username', $credentials['email']);
            })
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is currently inactive. Please contact your school administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        if (isset($credentials['role']) && $user->role !== $credentials['role']) {
            return response()->json([
                'message' => 'This account does not belong to the selected login type.',
                'code' => 'role_mismatch',
            ], 403);
        }

        $token = $user->createToken('school-saas')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
