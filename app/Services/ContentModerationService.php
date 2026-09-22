<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentModerationService
{
    protected $apiKey;
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function checkImage(string $imageUrl): array
    {
        try {
            $imageContent = file_get_contents($imageUrl);
            $base64Image = base64_encode($imageContent);

            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
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

            if (!$response->successful()) {
                Log::error('Gemini content check failed: ' . $response->body());
                return [
                    'passed' => false,
                    'message' => 'We could not verify this image right now. Please try again in a moment.',
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
                    'passed' => false,
                    'message' => 'We could not verify this image right now. Please try again in a moment.',
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
            Log::error('Content moderation check failed: ' . $e->getMessage());
            return [
                'passed' => false,
                'message' => 'We could not verify this image right now. Please try again in a moment.',
            ];
        }
    }
}
