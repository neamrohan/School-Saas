<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account is currently inactive. Please contact your school administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        if ($user && $user->role !== 'super_admin' && $user->school_id && ! $user->school()->exists()) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your school is unavailable. Please contact your Super Admin.',
                'code' => 'school_unavailable',
            ], 403);
        }

        return $next($request);
    }
}
