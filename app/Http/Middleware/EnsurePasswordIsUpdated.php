<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePasswordIsUpdated
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->role === 'student' && $user->password_is_temporary) {
            // Only the profile page, the password endpoints and logout stay reachable
            if ($request->is('api/profile') || $request->is('api/profile/set-new-password') || $request->is('api/auth/logout')) {
                return $next($request);
            }

            return response()->json([
                'error' => 'MUST_CHANGE_PASSWORD',
                'message' => 'You must create a new password before using FindNest.',
                'redirect' => '/profile',
            ], 403);
        }

        return $next($request);
    }
}
