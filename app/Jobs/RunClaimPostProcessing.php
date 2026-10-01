<?php
namespace App\Jobs;

use App\Models\Claim;
use App\Models\AiMatch;
use App\Models\AuditLog;
use App\Services\MatchScoreService;
use App\Services\OwnershipQuestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunClaimPostProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $claimId;

    public function __construct(int $claimId)
    {
        $this->claimId = $claimId;
    }

    public function handle(): void
    {
        $claim = Claim::find($this->claimId);
        if (!$claim) {
            return;
        }

        if ($claim->proof_photo_url) {
            $match = AiMatch::with('foundRecord')->find($claim->match_id);
            $foundPhotoUrl = $match?->foundRecord?->photo_url;

            if ($foundPhotoUrl) {
                $matchService = new MatchScoreService();
                $photoScore = $matchService->compareClaimPhotos($claim->proof_photo_url, $foundPhotoUrl);

                if ($photoScore !== null) {
                    $claim->update(['photo_similarity_score' => $photoScore]);

                    AuditLog::create([
                        'user_id' => $claim->student_id,
                        'action' => 'Claim Photo Similarity Scored',
                        'target_type' => 'claims',
                        'target_id' => $claim->id,
                        'details' => 'AI compared claimant evidence photo against found item photo: ' . $photoScore . '% similarity',
                        'performed_by' => 'System: AI Matching Engine',
                        'ip_address' => 'system',
                    ]);
                }
            }
        }

        $questionService = new OwnershipQuestionService();
        $questionResult = $questionService->generateQuestions($claim);

        if ($questionResult['status'] === 'generated') {
            AuditLog::create([
                'user_id' => $claim->student_id,
                'action' => 'Ownership Questions Generated',
                'target_type' => 'claims',
                'target_id' => $claim->id,
                'details' => 'AI generated ownership verification questions for this claim',
                'performed_by' => 'System: AI Engine',
                'ip_address' => 'system',
            ]);
        } elseif ($questionResult['status'] === 'skipped') {
            $claim->update([
                'verification_skipped' => true,
                'verification_skip_reason' => $questionResult['reason'],
            ]);

            AuditLog::create([
                'user_id' => $claim->student_id,
                'action' => 'Ownership Questions Skipped',
                'target_type' => 'claims',
                'target_id' => $claim->id,
                'details' => 'AI determined this item has no distinguishing features to verify: ' . $questionResult['reason'],
                'performed_by' => 'System: AI Engine',
                'ip_address' => 'system',
            ]);
        }
    }
}
