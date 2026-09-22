<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use App\Models\Notification;
use App\Models\AuditLog;
use Carbon\Carbon;

class CheckUnclaimedItems extends Command
{
    protected $signature = 'items:check-unclaimed';
    protected $description = 'Flags unclaimed items past 10 days and disposal review past 30 days, notifies students and logs for admin';

    public function handle()
    {
        $tenDaysAgo = Carbon::now()->subDays(10);
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $toFlag = FoundItemRecord::where('status', 'unclaimed')
            ->whereNull('unclaimed_flagged_at')
            ->where('created_at', '<=', $tenDaysAgo)
            ->get();

        foreach ($toFlag as $item) {
            $item->update(['unclaimed_flagged_at' => Carbon::now()]);

            AuditLog::create([
                'user_id' => null,
                'action' => 'Unclaimed Item Flagged',
                'target_type' => 'found_item_records',
                'target_id' => $item->id,
                'details' => 'Item "' . $item->item_name . '" has been unclaimed for over 10 days',
                'performed_by' => 'System: Unclaimed Monitor',
                'ip_address' => 'system',
            ]);

            $matchingReports = LostItemReport::where('status', 'searching')
                ->where('category', $item->category)
                ->get();

            foreach ($matchingReports as $report) {
                Notification::create([
                    'user_id' => $report->user_id,
                    'match_id' => null,
                    'title' => 'Unclaimed Item Reminder',
                    'message' => 'An unclaimed item in the "' . $item->category . '" category has been at the office for over 10 days. Check if it matches your lost report for "' . $report->item_name . '".',
                    'type' => 'reminder',
                    'is_read' => false,
                    'sent_at' => Carbon::now(),
                ]);
            }
        }

        $toDisposalReview = FoundItemRecord::where('status', 'unclaimed')
            ->where('needs_disposal_review', false)
            ->where('created_at', '<=', $thirtyDaysAgo)
            ->get();

        foreach ($toDisposalReview as $item) {
            $item->update(['needs_disposal_review' => true]);

            AuditLog::create([
                'user_id' => null,
                'action' => 'Item Flagged for Disposal Review',
                'target_type' => 'found_item_records',
                'target_id' => $item->id,
                'details' => 'Item "' . $item->item_name . '" has reached the 30-day disposal review threshold',
                'performed_by' => 'System: Unclaimed Monitor',
                'ip_address' => 'system',
            ]);
        }

        $this->info('Flagged ' . $toFlag->count() . ' items as unclaimed, ' . $toDisposalReview->count() . ' flagged for disposal review.');
    }
}
