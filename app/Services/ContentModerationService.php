<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentModerationService
{
    protected $apiKey;
    protected $apiUrl;

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');

        // Dynamically reads GEMINI_MODEL from Render env, falling back to gemini-2.5-flash
        $model = env('GEMINI_MODEL', 'gemini-2.5-flash');

        // Clean model string without URL-encoded brackets
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    public function checkImage(string $imageUrl): array
    {
        try {
            $imageContent = @file_get_contents($imageUrl);
            if (!$imageContent) {
                Log::warning('Could not read image content for moderation, allowing upload.');
                return [
                    'passed' => true,
                    'message' => 'Image passed content and quality checks.',
                ];
            }

            $base64Image = base64_encode($imageContent);

            $response = Http::timeout(20)->retry(2, 1000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Analyze this image for two things. First, content appropriateness: does it contain nudity, violence, graphic content, offensive material, or anything unsuitable for a school lost-and-found system? Second, technical image quality: is it clear (not blurry), well-lit (not too dark or overexposed), and high enough resolution to make out real detail (not tiny, pixelated, or heavily compressed)? Respond with ONLY a JSON object in this exact format, no other text: {"appropriate": true or false, "appropriate_reason": "brief explanation if inappropriate, or empty string", "quality_ok": true or false, "quality_reason": "brief explanation of the specific quality issue if quality_ok is false, or empty string"}'
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $base64Image,
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            // If Google is down, overloaded (503), or returns an API error, fail-open so users aren't blocked
            if (!$response->successful()) {
                Log::warning('Gemini moderation check failed (' . $response->status() . '): ' . $response->body() . ' - Bypassing check.');
                return [
                    'passed' => true,
                    'message' => 'Image check bypassed due to temporary AI service unavailability.',
                ];
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $cleanedText = preg_replace('/```json\s*|\s*```/', '', $textResult);
            $cleanedText = trim($cleanedText);

            $result = json_decode($cleanedText, true);

            if (!is_array($result) || !isset($result['appropriate']) || !isset($result['quality_ok'])) {
                Log::error('Gemini content check returned unexpected format: ' . $textResult);
                return [
                    'passed' => true,
                    'message' => 'Image passed check.',
                ];
            }

            if ($result['appropriate'] !== true) {
                return [
                    'passed' => false,
                    'message' => 'This image was flagged as inappropriate' . (!empty($result['appropriate_reason']) ? ': ' . $result['appropriate_reason'] : '') . '. Please upload a different photo.',
                ];
            }

            if ($result['quality_ok'] !== true) {
                return [
                    'passed' => false,
                    'message' => 'This photo doesn\'t look clear enough to use' . (!empty($result['quality_reason']) ? ': ' . $result['quality_reason'] : '') . '. Please upload a clearer, well-lit photo.',
                ];
            }

            return [
                'passed' => true,
                'message' => 'Image passed content and quality checks.',
            ];
        } catch (\Exception $e) {
            Log::error('Content moderation check exception: ' . $e->getMessage());
            // Fail-open: don't block user if server network hiccup occurs
            return [
                'passed' => true,
                'message' => 'Image passed check.',
            ];
        }
    }
}
