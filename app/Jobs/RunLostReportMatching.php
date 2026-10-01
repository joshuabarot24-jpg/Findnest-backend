<?php
namespace App\Jobs;

use App\Models\LostItemReport;
use App\Services\MatchScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunLostReportMatching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reportId;

    public function __construct(int $reportId)
    {
        $this->reportId = $reportId;
    }

    public function handle(): void
    {
        $report = LostItemReport::find($this->reportId);
        if (!$report) {
            return;
        }

        $matchService = new MatchScoreService();
        $matchService->checkNewLostReport($report);
    }
}
