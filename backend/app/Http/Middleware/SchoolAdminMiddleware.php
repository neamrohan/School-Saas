<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SchoolAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'school_admin') {
            return response()->json([
                'message' => 'School admin access required.',
            ], 403);
        }

        if (! $user->school_id) {
            return response()->json([
                'message' => 'School access denied.',
            ], 403);
        }

        return $next($request);
    }
}