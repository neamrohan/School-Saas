<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SchoolAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id) {
            return response()->json([
                'message' => 'School access denied.',
            ], 403);
        }

        if ($user->role === 'student') {
            return response()->json([
                'message' => 'Student access is limited to personal profile data.',
            ], 403);
        }

        return $next($request);
    }
}
