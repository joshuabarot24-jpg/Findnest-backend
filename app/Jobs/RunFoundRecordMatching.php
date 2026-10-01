<?php
namespace App\Jobs;

use App\Models\FoundItemRecord;
use App\Services\MatchScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunFoundRecordMatching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $foundId;

    public function __construct(int $foundId)
    {
        $this->foundId = $foundId;
    }

    public function handle(): void
    {
        $found = FoundItemRecord::find($this->foundId);
        if (!$found) {
            return;
        }

        $matchService = new MatchScoreService();
        $matchService->checkNewFoundRecord($found);
    }
}
