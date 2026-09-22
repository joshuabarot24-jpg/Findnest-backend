<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminPrivilege
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $user = $request->user();

        if ($user && $user->role === 'admin') {
            $blockedPages = $user->privileges ?? [];
            if (in_array($page, $blockedPages)) {
                return response()->json([
                    'message' => 'You do not have access to this page. Contact the Super Admin if you believe this is a mistake.',
                ], 403);
            }
        }

        return $next($request);
    }
}
