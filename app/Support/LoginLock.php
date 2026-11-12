<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;

// Failed password handling: every 5th failed attempt locks the account for longer each time
class LoginLock
{
    private const MINUTES = [1, 5, 15, 30, 60];

    public static function fail(User $user): void
    {
        // 24 hours without a failed attempt: an old streak no longer counts
        $last = $user->last_failed_login_at ? Carbon::parse($user->last_failed_login_at) : null;
        $count = ($last && $last->lt(now()->subHours(24))) ? 0 : (int) $user->failed_login_attempts;
        $count++;

        $changes = ['failed_login_attempts' => $count, 'last_failed_login_at' => now()];

        if ($count % 5 === 0) {
            $level = min(intdiv($count, 5), count(self::MINUTES)) - 1;
            $changes['login_locked_until'] = now()->addMinutes(self::MINUTES[$level]);
        }

        $user->forceFill($changes)->save();
    }
}
