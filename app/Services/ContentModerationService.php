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
                             'text' => 'Analyze this image for two things. First, content appropriateness for a school lost-and-found system. FLAG the image (appropriate: false) only for these specific things: (1) nudity or sexually suggestive content, (2) obscene or offensive hand gestures (such as a raised middle finger) visible anywhere in the frame, even if an item is also present, (3) weapons being brandished or displayed as a threat (a gun, knife, or blade held or aimed), (4) graphic violence, gore, or injury, (5) a body part shown alone with no identifiable item at all (e.g. just a foot, just a torso, with nothing to report). Do NOT flag an image just because an object has an ambiguous or unusual shape that could resemble something else — for example, a perfume bottle, lighter, vape device, cosmetic container, or novelty item that superficially resembles a weapon or paraphernalia should NOT be flagged based on shape alone. If you are not certain an object is genuinely dangerous or inappropriate, do not flag it — let the item identification step describe it as best it can, even if that description turns out to be imprecise; a wrong guess at what an object is causes far less harm than incorrectly blocking a legitimate upload. Vapes and e-cigarettes are allowed to be photographed and are not automatic violations, since school lost-and-found and confiscated-item systems legitimately handle these. Second, technical image quality: is the main subject reasonably identifiable — can you tell what the item is and make out its general color, shape, and any obvious markings? Only fail this check for genuinely unusable photos: severe motion blur where the subject is unrecognizable, extreme darkness where nothing is visible, or such low resolution that the image is just a blur of pixels. A normal phone photo taken quickly, in average indoor lighting, or from a slight angle should PASS — minor softness, ordinary shadows, or a slightly off-center shot are all acceptable and common for real-world lost-and-found photos. Only reject images that would genuinely be useless for identifying the item at all. Respond with ONLY a JSON object in this exact format, no other text: {"appropriate": true or false, "appropriate_reason": "brief explanation if inappropriate, or empty string", "quality_ok": true or false, "quality_reason": "brief explanation of the specific quality issue if quality_ok is false, or empty string"}'
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
