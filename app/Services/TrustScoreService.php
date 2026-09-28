<?php
namespace App\Services;

use App\Models\User;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Carbon\Carbon;

class TrustScoreService
{
    const RESTRICTION_THRESHOLD = 50;
    const RESTRICTION_DAYS = 7;

    public static function restrictionThreshold(): int
    {
        return (int) SystemSetting::get('trust_restriction_threshold', self::RESTRICTION_THRESHOLD);
    }

    public static function restrictionDays(): int
    {
        return (int) SystemSetting::get('trust_restriction_days', self::RESTRICTION_DAYS);
    }

    public function adjustScore(User $student, int $delta, string $reason): void
    {
        $oldScore = $student->trust_score;
        $newScore = max(0, min(100, $oldScore + $delta));

        $student->update(['trust_score' => $newScore]);

        AuditLog::create([
            'user_id' => $student->id,
            'action' => 'Trust Score Adjusted',
            'target_type' => 'users',
            'target_id' => $student->id,
            'details' => 'Trust score changed from ' . $oldScore . ' to ' . $newScore . ' (' . ($delta >= 0 ? '+' : '') . $delta . '). Reason: ' . $reason,
            'performed_by' => 'System: Trust Score Engine',
            'ip_address' => 'system',
        ]);

        $threshold = self::restrictionThreshold();
        $days = self::restrictionDays();

        if ($newScore < $threshold && !$student->is_restricted) {
            $student->update([
                'is_restricted' => true,
                'restriction_reason' => 'Trust score fell below ' . $threshold . ' (' . $reason . ')',
                'restricted_until' => $days > 0 ? Carbon::now()->addDays($days) : null,
            ]);

            AuditLog::create([
                'user_id' => $student->id,
                'action' => 'Account Restricted',
                'target_type' => 'users',
                'target_id' => $student->id,
                'details' => 'Student auto-restricted from submitting claims '
                    . ($days > 0 ? 'for ' . $days . ' day' . ($days === 1 ? '' : 's') : 'until an administrator re-enables access')
                    . ' due to low trust score',
                'performed_by' => 'System: Trust Score Engine',
                'ip_address' => 'system',
            ]);
        }
    }

    public function approvedClaim(User $student): void
    {
        $this->adjustScore($student, 5, 'Claim approved');
    }

    public function rejectedClaim(User $student): void
    {
        $this->adjustScore($student, -10, 'Claim rejected by admin');
    }

    public function failedVerification(User $student): void
    {
        $this->adjustScore($student, -5, 'Failed ownership verification questions');
    }

    public function isRestricted(User $student): bool
    {
        if (!$student->is_restricted) {
            return false;
        }

        if ($student->restricted_until && Carbon::now()->isAfter($student->restricted_until)) {
            $student->update(['is_restricted' => false, 'restriction_reason' => null, 'restricted_until' => null]);
            return false;
        }

        return true;
    }
}
