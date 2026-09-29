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

    public function generateQuestions(Claim $claim): array
    {
        $match = $claim->match()->with('foundRecord')->first();

        if (!$match || !$match->foundRecord) {
            Log::error('Cannot generate ownership questions: no found record linked to claim ' . $claim->id);
            return ['status' => 'failed'];
        }

        $description = $match->foundRecord->ai_description ?: $match->foundRecord->description;

        if (empty($description)) {
            Log::error('Cannot generate ownership questions: found record has no description for claim ' . $claim->id);
            return ['status' => 'failed'];
        }

        try {
            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "You are deciding whether an item has enough distinguishing detail to generate ownership verification questions. First, check the description below: does it contain ANY genuinely distinguishing details beyond a generic type, color, or category — such as brand markings, model names, unique printed text, scratches, dents, stickers, engravings, or other specific identifying marks a random person would not know? If it does NOT contain such details (the description is generic, e.g. just 'black backpack' or 'silver key'), respond with ONLY this JSON, no other text: {\"skip\": true, \"reason\": \"short plain-language reason, under 15 words\"}. If it DOES contain distinguishing details, create exactly 3 multiple-choice ownership verification questions, each testing a subtle, specific, non-obvious detail from those distinguishing features. Do NOT ask about the general category or obvious features. Each question needs exactly 4 answer options, with only one correct. Respond with ONLY this JSON, no other text: {\"skip\": false, \"questions\": [{\"question\": \"...\", \"option_a\": \"...\", \"option_b\": \"...\", \"option_c\": \"...\", \"option_d\": \"...\", \"correct_option\": \"a\"}, ...]}\n\nItem description: {$description}"
                            ]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Ownership question generation failed: ' . $response->body());
                return ['status' => 'failed'];
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $cleanedText = trim(preg_replace('/```json\s*|\s*```/', '', $textResult));

            $result = json_decode($cleanedText, true);

            if (!is_array($result)) {
                Log::error('Ownership questions returned unexpected format: ' . $textResult);
                return ['status' => 'failed'];
            }

            if (($result['skip'] ?? false) === true) {
                return [
                    'status' => 'skipped',
                    'reason' => $result['reason'] ?? 'No distinguishing features detected',
                ];
            }

            $questions = $result['questions'] ?? null;

            if (!is_array($questions) || count($questions) === 0) {
                Log::error('Ownership questions returned unexpected format: ' . $textResult);
                return ['status' => 'failed'];
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

            return ['status' => 'generated'];
        } catch (\Exception $e) {
            Log::error('Ownership question generation exception: ' . $e->getMessage());
            return ['status' => 'failed'];
        }
    }
}
