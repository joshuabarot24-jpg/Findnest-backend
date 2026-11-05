<?php
namespace App\Console\Commands;

use App\Models\FoundItemRecord;
use Illuminate\Console\Command;

class FixSurrenderStatuses extends Command
{
    protected $signature = 'items:fix-surrender-statuses';
    protected $description = 'Moves existing unreceived found items to the correct status';

    public function handle(): int
    {
        // Unreceived and still "unclaimed": now waiting for surrender
        $waiting = FoundItemRecord::where('receipt_confirmed', false)
            ->where('status', 'unclaimed')
            ->update([
                'status' => 'waiting_for_surrender',
                'unclaimed_flagged_at' => null,
                'needs_disposal_review' => false,
            ]);

        // Unreceived but marked for disposal, or auto-disposed by the old rule: post discarded
        $discarded = FoundItemRecord::where('receipt_confirmed', false)
            ->where(function ($q) {
                $q->where('status', 'for_disposal')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'disposed')->where('disposal_notes', 'like', 'Auto-rejected%');
                    });
            })
            ->update(['status' => 'unsurrendered_expired']);

        $this->info("{$waiting} moved to waiting_for_surrender, {$discarded} moved to post discarded.");
        return self::SUCCESS;
    }
}
