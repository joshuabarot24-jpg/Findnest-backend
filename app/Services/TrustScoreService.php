<?php
namespace App\Services;

use App\Models\User;
use App\Models\AuditLog;
use Carbon\Carbon;

class TrustScoreService
{
    const RESTRICTION_THRESHOLD = 50;
    const RESTRICTION_DAYS = 7;

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

        if ($newScore < self::RESTRICTION_THRESHOLD && !$student->is_restricted) {
            $student->update([
                'is_restricted' => true,
                'restriction_reason' => 'Trust score fell below ' . self::RESTRICTION_THRESHOLD . ' (' . $reason . ')',
                'restricted_until' => Carbon::now()->addDays(self::RESTRICTION_DAYS),
            ]);

            AuditLog::create([
                'user_id' => $student->id,
                'action' => 'Account Restricted',
                'target_type' => 'users',
                'target_id' => $student->id,
                'details' => 'Student auto-restricted from submitting claims for ' . self::RESTRICTION_DAYS . ' days due to low trust score',
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
