<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            $reason = null;

            if (!$user->is_active) {
                $reason = 'This account has been archived. Please contact the Super Admin.';
            } elseif ($user->is_restricted) {
                if ($user->restricted_until && now()->isAfter($user->restricted_until)) {
                    // The restriction has run out: lift it and let the request through
                    $user->update(['is_restricted' => false, 'restriction_reason' => null, 'restricted_until' => null]);
                } elseif ($user->role === 'student') {
                    $reason = 'Your account has been restricted. Please contact the Guidance Office for assistance.';
                } else {
                    $reason = 'Your account has been suspended by the Super Admin. Please contact them for assistance.';
                }
            }

            if ($reason) {
                // End this session, so the old token cannot be used again
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }

                return response()->json(['error' => 'ACCOUNT_DISABLED', 'message' => $reason], 401);
            }
        }

        return $next($request);
    }
}
