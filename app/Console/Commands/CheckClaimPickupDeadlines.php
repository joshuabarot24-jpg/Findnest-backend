<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Claim;
use App\Models\AiMatch;
use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Services\TrustScoreService;
use Carbon\Carbon;

class CheckClaimPickupDeadlines extends Command
{
    protected $signature = 'claims:check-pickup-deadlines';
    protected $description = 'Sends reminders for approved claims nearing pickup deadline, marks expired ones as abandoned';

    public function handle()
    {
        $approaching = Claim::where('claim_status', 'approved')
            ->whereNull('collected_at')
            ->where('reminder_sent', false)
            ->whereDate('pickup_deadline', Carbon::tomorrow())
            ->get();

        foreach ($approaching as $claim) {
            Notification::create([
                'user_id' => $claim->student_id,
                'match_id' => $claim->match_id,
                'title' => 'Pickup Reminder',
                'message' => 'Your approved item must be collected by tomorrow. Visit the Guidance Office before your pickup deadline expires.',
                'type' => 'reminder',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);
            $claim->update(['reminder_sent' => true]);
        }

        $expired = Claim::where('claim_status', 'approved')
            ->whereNull('collected_at')
            ->whereDate('pickup_deadline', '<', Carbon::today())
            ->get();

        $trustService = new TrustScoreService();

        foreach ($expired as $claim) {
            $claim->update(['claim_status' => 'abandoned']);

            $match = AiMatch::find($claim->match_id);
            if ($match) {
                LostItemReport::find($match->report_id)?->update(['status' => 'searching']);
                FoundItemRecord::find($match->found_id)?->update(['status' => 'unclaimed']);
            }

            $student = \App\Models\User::find($claim->student_id);
            if ($student) {
                $trustService->adjustScore($student, -5, 'Approved claim not collected within pickup window');
            }

            Notification::create([
                'user_id' => $claim->student_id,
                'match_id' => $claim->match_id,
                'title' => 'Claim Abandoned',
                'message' => 'Your pickup window has expired. The item has returned to unclaimed status. Contact the Guidance Office if you still need to claim it.',
                'type' => 'status',
                'is_read' => false,
                'sent_at' => Carbon::now(),
            ]);

            AuditLog::create([
                'user_id' => null,
                'action' => 'Claim Abandoned',
                'target_type' => 'claims',
                'target_id' => $claim->id,
                'details' => 'Pickup deadline expired without collection, item returned to unclaimed status',
                'performed_by' => 'System: Pickup Deadline Monitor',
                'ip_address' => 'system',
            ]);
        }

        $this->info('Sent ' . $approaching->count() . ' reminders, abandoned ' . $expired->count() . ' claims.');
    }
}
