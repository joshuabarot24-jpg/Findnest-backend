<?php
namespace App\Services;

use App\Models\LostItemReport;
use App\Models\FoundItemRecord;
use App\Models\AiMatch;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Services\FcmService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MatchScoreService
{
    protected $apiKey;
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function checkNewLostReport(LostItemReport $report)
    {
        $candidates = FoundItemRecord::where('status', 'unclaimed')->get();

        foreach ($candidates as $found) {
            $this->evaluatePair($report, $found);
        }
    }

    public function checkNewFoundRecord(FoundItemRecord $found)
    {
        $candidates = LostItemReport::where('status', 'searching')->get();

        foreach ($candidates as $report) {
            $this->evaluatePair($report, $found);
        }
    }

    protected function evaluatePair(LostItemReport $report, FoundItemRecord $found)
    {
        $existing = AiMatch::where('report_id', $report->id)
            ->where('found_id', $found->id)
            ->first();

        if ($existing) {
            return;
        }

        $descriptionScore = $this->compareDescriptions(
            $report->ai_description ?: $report->description,
            $found->ai_description ?: $found->description
        );

        $photoScore = $this->comparePhotos($report->photo_url, $found->photo_url);

        $categoryScore = ($report->category === $found->category) ? 100 : 0;

        $temporalSpatialScore = $this->calculateTemporalSpatialScore($report, $found);

        $baseScore = ($descriptionScore * 0.35) +
            ($photoScore * 0.35) +
            ($categoryScore * 0.15) +
            ($temporalSpatialScore * 0.15);

        $bonusPoints = $this->calculateBonusPoints($report, $found);

        $finalScore = min(100, round($baseScore + $bonusPoints));

        $threshold = (int) \App\Models\SystemSetting::get('match_confidence_threshold', 75);
        $matchStatus = $finalScore < $threshold ? null : 'pending';

        if ($matchStatus === null) {
            return;
        }

        $daysSinceFound = Carbon::parse($found->created_at)->diffInDays(Carbon::parse($report->created_at));
        $reverseEngineeringFlag = $daysSinceFound > 3;

        $match = AiMatch::create([
            'report_id' => $report->id,
            'found_id' => $found->id,
            'confidence_score' => $finalScore,
            'attributes' => json_encode([
                'description_score' => $descriptionScore,
                'photo_score' => $photoScore,
                'category_score' => $categoryScore,
                'temporal_spatial_score' => $temporalSpatialScore,
            ]),
            'match_status' => $matchStatus,
            'matched_at' => Carbon::now(),
            'reverse_engineering_flag' => $reverseEngineeringFlag,
        ]);

        AuditLog::create([
            'user_id' => $report->user_id,
            'action' => 'AI Match Generated',
            'target_type' => 'ai_matches',
            'target_id' => $match->id,
            'details' => 'AI matched "' . $report->item_name . '" with found item "' . $found->item_name . '" at ' . $finalScore . '% confidence'
                . ($reverseEngineeringFlag ? ' — FLAGGED: lost report submitted ' . $daysSinceFound . ' days after found item was recorded' : ''),
            'performed_by' => 'System: AI Matching Engine',
            'ip_address' => request()->ip() ?? 'system',
        ]);

        if ($finalScore >= 75) {
            $this->notifyStudent($report, $found, $finalScore, $match->id);
        }
    }

    protected function compareDescriptions(?string $descriptionA, ?string $descriptionB): int
    {
        if (empty($descriptionA) || empty($descriptionB)) {
            return 0;
        }

        try {
            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "Compare these two item descriptions carefully and determine how likely they describe the SAME physical item. Weigh these factors: (1) category and type of item — must be genuinely compatible, (2) primary and secondary color — exact or very close matches score higher, (3) brand or markings if mentioned in either description, (4) material, (5) distinctive features like scratches, dents, stickers, or keychains — these are strong identity signals if they match. If key identifying details conflict (e.g. one says black, the other says white; one mentions a specific brand the other contradicts), score this significantly lower even if the general item type matches. Rate the overall similarity on a scale of 0 to 100, where 90-100 means near-certain same item, 70-89 means likely same item with minor uncertainty, 40-69 means possible but uncertain, and below 40 means unlikely to be the same item. Respond with ONLY a JSON object in this exact format, no other text: {\"similarity_score\": number from 0 to 100}\n\nDescription A: {$descriptionA}\n\nDescription B: {$descriptionB}"
                            ]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Description comparison failed: ' . $response->body());
                return 0;
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $cleanedText = trim(preg_replace('/```json\s*|\s*```/', '', $textResult));

            $result = json_decode($cleanedText, true);

            return is_array($result) && isset($result['similarity_score'])
                ? (int) $result['similarity_score']
                : 0;
        } catch (\Exception $e) {
            Log::error('Description comparison exception: ' . $e->getMessage());
            return 0;
        }
    }

    protected function calculateTemporalSpatialScore(LostItemReport $report, FoundItemRecord $found): int
    {
        $daysDiff = abs(Carbon::parse($report->date_lost)->diffInDays(Carbon::parse($found->date_found)));

        if ($daysDiff <= 1) {
            $dateScore = 100;
        } elseif ($daysDiff <= 3) {
            $dateScore = 75;
        } elseif ($daysDiff <= 7) {
            $dateScore = 50;
        } else {
            $dateScore = 20;
        }

        $locationA = strtolower(trim($report->location_lost));
        $locationB = strtolower(trim($found->location_found));

        similar_text($locationA, $locationB, $locationPercent);

        return (int) round(($dateScore + $locationPercent) / 2);
    }

    protected function notifyStudent(LostItemReport $report, FoundItemRecord $found, int $score, int $matchId)
    {
        Notification::create([
            'user_id' => $report->user_id,
            'match_id' => $matchId,
            'title' => 'Possible Match Found!',
            'message' => 'We found a ' . $score . '% match for your lost "' . $report->item_name . '". Check Claim Status to view the details.',
            'type' => 'match',
            'is_read' => false,
            'sent_at' => Carbon::now(),
        ]);

        $student = \App\Models\User::find($report->user_id);
        if ($student && $student->fcm_token) {
            $fcm = new FcmService();
            $fcm->sendToUser(
                $student->fcm_token,
                'Possible Match Found',
                'We found a ' . $score . '% match for your lost "' . $report->item_name . '".',
                ['type' => 'ai_match', 'report_id' => (string) $report->id]
            );
        }
    }

    protected function calculateBonusPoints(LostItemReport $report, FoundItemRecord $found): int
    {
        $bonus = 0;

        if (!empty($report->item_name) && !empty($found->item_name)) {
            similar_text(strtolower($report->item_name), strtolower($found->item_name), $namePercent);
            if ($namePercent >= 70) {
                $bonus += 10;
            }
        }

        if (!empty($report->brand_model) && !empty($found->brand_model)) {
            $brandA = strtolower(trim($report->brand_model));
            $brandB = strtolower(trim($found->brand_model));
            if ($brandA === $brandB || str_contains($brandA, $brandB) || str_contains($brandB, $brandA)) {
                $bonus += 10;
            }
        }

        if (!empty($report->primary_color) && !empty($found->primary_color)) {
            if (strtolower(trim($report->primary_color)) === strtolower(trim($found->primary_color))) {
                $bonus += 5;
            }
        }

        $daysDiff = abs(Carbon::parse($report->date_lost)->diffInDays(Carbon::parse($found->date_found)));
        if ($daysDiff <= 1) {
            $bonus += 5;
        }

        return $bonus;
    }

    public function compareClaimPhotos(string $claimantPhotoUrl, string $foundItemPhotoUrl): ?int
    {
        try {
            $claimantImage = base64_encode(file_get_contents($claimantPhotoUrl));
            $foundImage = base64_encode(file_get_contents($foundItemPhotoUrl));

            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Compare these two photos. The first is evidence submitted by a student claiming ownership. The second is the original found item photo on file. Rate how likely these show the exact same physical item, on a scale of 0 to 100, considering color, shape, brand, distinctive marks, and overall visual similarity. Respond with ONLY a JSON object: {"similarity_score": number from 0 to 100}'
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $claimantImage,
                                ]
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $foundImage,
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Claim photo comparison failed: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $cleanedText = trim(preg_replace('/```json\s*|\s*```/', '', $textResult));
            $result = json_decode($cleanedText, true);

            return is_array($result) && isset($result['similarity_score']) ? (int) $result['similarity_score'] : null;
        } catch (\Exception $e) {
            Log::error('Claim photo comparison exception: ' . $e->getMessage());
            return null;
        }
    }

    protected function comparePhotos(?string $photoUrlA, ?string $photoUrlB): int
    {
        if (empty($photoUrlA) || empty($photoUrlB)) {
            return 0;
        }

        try {
            $imageA = base64_encode(file_get_contents($photoUrlA));
            $imageB = base64_encode(file_get_contents($photoUrlB));

            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Compare these two photos, one from a lost item report and one from a found item report. Rate how likely they show the SAME physical item, on a scale of 0 to 100. Consider the item itself first (shape, color, brand, distinctive marks) as the primary signal. If the specific item cannot be clearly compared, also weigh secondary visual context clues that might indicate the same environment or scene (matching background objects, furniture, or surroundings), but these should only moderately raise the score, never make it high on their own. Respond with ONLY a JSON object in this exact format, no other text: {"similarity_score": number from 0 to 100}'
                            ],
                            ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageA]],
                            ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageB]],
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Photo comparison failed: ' . $response->body());
                return 0;
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $cleanedText = trim(preg_replace('/```json\s*|\s*```/', '', $textResult));
            $result = json_decode($cleanedText, true);

            return is_array($result) && isset($result['similarity_score']) ? (int) $result['similarity_score'] : 0;
        } catch (\Exception $e) {
            Log::error('Photo comparison exception: ' . $e->getMessage());
            return 0;
        }
    }

}
