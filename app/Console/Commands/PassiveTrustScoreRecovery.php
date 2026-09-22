<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\AuditLog;

class PassiveTrustScoreRecovery extends Command
{
    protected $signature = 'trust:passive-recovery';
    protected $description = 'Increases trust score by 1 point daily for students below 100, capped at 100';

    public function handle()
    {
        $students = User::where('role', 'student')
            ->where('trust_score', '<', 100)
            ->get();

        $recovered = 0;

        foreach ($students as $student) {
            $oldScore = $student->trust_score;
            $newScore = min(100, $oldScore + 1);

            $student->update(['trust_score' => $newScore]);

            AuditLog::create([
                'user_id' => $student->id,
                'action' => 'Trust Score Passive Recovery',
                'target_type' => 'users',
                'target_id' => $student->id,
                'details' => 'Trust score passively increased from ' . $oldScore . ' to ' . $newScore,
                'performed_by' => 'System: Trust Score Engine',
                'ip_address' => 'system',
            ]);

            $recovered++;
        }

        $this->info('Passively recovered trust score for ' . $recovered . ' student(s).');
    }
}
