<?php
namespace App\Support;

use Carbon\Carbon;

class ReportTime
{
    public const OPEN = 360;   // 6:00 AM
    public const CLOSE = 1140; // 7:00 PM

    // "3:30 PM" or "15:30" -> minutes after midnight, or null when it is not a time
    public static function minutes(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') return null;
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)?\s*$/i', $raw, $m)) return null;
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($min > 59) return null;
        if (!empty($m[3])) {
            if ($h < 1 || $h > 12) return null;
            $h = $h % 12;
            if (strtoupper($m[3]) === 'PM') $h += 12;
        } elseif ($h > 23) {
            return null;
        }
        return $h * 60 + $min;
    }

    // An error message, or null when the time is allowed. A missing time is allowed
    public static function error(?string $time, ?string $date): ?string
    {
        if ($time === null || trim($time) === '') return null;
        $mins = self::minutes($time);
        if ($mins === null) return 'The time is not valid.';
        if ($mins < self::OPEN || $mins > self::CLOSE) return 'The time must be between 6:00 AM and 7:00 PM.';

        $now = Carbon::now('Asia/Manila');
        if ($date && Carbon::parse($date, 'Asia/Manila')->isSameDay($now) && $mins > $now->hour * 60 + $now->minute) {
            return 'The time cannot be in the future.';
        }
        return null;
    }
}
