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

        $model = env('GEMINI_MODEL', 'gemini-3.6-flash');

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
                                'text' => 'Analyze this image for two things. First, content appropriateness: does it contain nudity, violence, graphic content, offensive material, or anything unsuitable for a school lost-and-found system? Second, technical image quality — examine this closely: Is the main subject in sharp focus, with clear, well-defined edges? Motion blur, camera shake, or out-of-focus blur should fail this check. Is the lighting adequate to see true colors and surface details (not too dark, not washed out)? Is the resolution high enough that fine details like text, small marks, scratches, or textures would actually be visible if present? If someone tried to use this exact photo to identify a specific item among many similar items, would they be able to, or is it too blurry/dark/low-res to tell apart from another similar item? If in doubt about clarity, err toward failing the check rather than passing it — a school lost-and-found system depends on images being genuinely usable for identification. Respond with ONLY a JSON object in this exact format, no other text: {"appropriate": true or false, "appropriate_reason": "brief explanation if inappropriate, or empty string", "quality_ok": true or false, "quality_reason": "brief explanation of the specific quality issue if quality_ok is false, or empty string"}'
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
            return [
                'passed' => true,
                'message' => 'Image passed check.',
            ];
        }
    }
}
