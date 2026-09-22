<?php
namespace App\Services;

use App\Models\Claim;
use App\Models\OwnershipQuestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OwnershipQuestionService
{
    protected $apiKey;
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function generateQuestions(Claim $claim): bool
    {
        $match = $claim->match()->with('foundRecord')->first();

        if (!$match || !$match->foundRecord) {
            Log::error('Cannot generate ownership questions: no found record linked to claim ' . $claim->id);
            return false;
        }

        $description = $match->foundRecord->ai_description ?: $match->foundRecord->description;

        if (empty($description)) {
            Log::error('Cannot generate ownership questions: found record has no description for claim ' . $claim->id);
            return false;
        }

        try {
            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "Based on this detailed description of a found item, create exactly 3 multiple-choice ownership verification questions. Each question should test a subtle, specific, non-obvious detail (such as color combinations, brand markings, distinctive features, materials, or small imperfections) that only the true owner would know. Do NOT ask about the general category or obvious features. Each question needs exactly 4 answer options, with only one correct. Respond with ONLY a JSON array in this exact format, no other text: [{\"question\": \"...\", \"option_a\": \"...\", \"option_b\": \"...\", \"option_c\": \"...\", \"option_d\": \"...\", \"correct_option\": \"a\"}, ...]\n\nItem description: {$description}"
                            ]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Ownership question generation failed: ' . $response->body());
                return false;
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $cleanedText = trim(preg_replace('/```json\s*|\s*```/', '', $textResult));

            $questions = json_decode($cleanedText, true);

            if (!is_array($questions) || count($questions) === 0) {
                Log::error('Ownership questions returned unexpected format: ' . $textResult);
                return false;
            }

            foreach ($questions as $q) {
                if (!isset($q['question'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['correct_option'])) {
                    continue;
                }

                OwnershipQuestion::create([
                    'claim_id' => $claim->id,
                    'question_text' => $q['question'],
                    'option_a' => $q['option_a'],
                    'option_b' => $q['option_b'],
                    'option_c' => $q['option_c'],
                    'option_d' => $q['option_d'],
                    'correct_option' => strtolower($q['correct_option']),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Ownership question generation exception: ' . $e->getMessage());
            return false;
        }
    }
}
