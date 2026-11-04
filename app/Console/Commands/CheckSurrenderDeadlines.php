<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FoundItemRecord;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Services\TrustScoreService;
use Carbon\Carbon;

class CheckSurrenderDeadlines extends Command
{
    protected $signature = 'items:check-surrender-deadlines';
    protected $description = 'Auto-rejects found item reports not surrendered within their deadline, flags the reporting student';

    public function handle()
    {
        $overdue = FoundItemRecord::where('receipt_confirmed', false)
            ->whereNotNull('surrender_deadline')
            ->where('surrender_deadline', '<', now())
            ->whereNotIn('status', ['disposed', 'unsurrendered_expired'])
            ->get();

        $trustService = new TrustScoreService();

        foreach ($overdue as $item) {
            $item->update([
                'status' => 'unsurrendered_expired',
                'disposal_notes' => 'Expired: item was not surrendered within the required window.',
            ]);

            $student = \App\Models\User::find($item->admin_id);

            if ($student) {
                $trustService->adjustScore($student, -5, 'Found item report not surrendered within the required window');

                Notification::create([
                    'user_id' => $student->id,
                    'match_id' => null,
                    'title' => 'Found Item Report Expired',
                    'message' => 'Your report for "' . $item->item_name . '" has expired because it was not surrendered to the Guidance Office within 2 school days. Your Trust Score has been deducted by 5 points. Please surrender items as soon as possible to maintain a healthy score.',
                    'type' => 'status',
                    'is_read' => false,
                    'sent_at' => Carbon::now(),
                ]);
            }

            AuditLog::create([
                'user_id' => null,
                'action' => 'Found Item Auto-Rejected',
                'target_type' => 'found_item_records',
                'target_id' => $item->id,
                'details' => 'Item "' . $item->item_name . '" auto-rejected: surrender deadline passed without confirmed receipt',
                'performed_by' => 'System: Surrender Deadline Monitor',
                'ip_address' => 'system',
            ]);
        }

        $this->info('Auto-rejected ' . $overdue->count() . ' overdue found item report(s).');
    }
}
