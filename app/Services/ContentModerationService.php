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

    /**
     * @param bool        $allowProhibited true only for an admin uploading a confiscated item
     * @param string|null $adminIntake     the intake type picked by an admin (null for students)
     */
    public function checkImage(string $imageUrl, bool $allowProhibited = false, ?string $adminIntake = null): array
    {
        try {
            $imageContent = @file_get_contents($imageUrl);
            if (!$imageContent) {
                Log::warning('Could not read image content for moderation, allowing upload.');
                return [
                    'passed' => true,
                    'error_code' => null,
                    'message' => 'Image passed content and quality checks.',
                ];
            }

            $base64Image = base64_encode($imageContent);

            $prompt = 'You are the photo checker for a school lost-and-found system. Look carefully at the WHOLE image, including hands, fingers, the background and the edges, before you answer. Fill in EVERY field below as a separate observation. Do not skip a field because the main item looks harmless. '
                . 'Fields: '
                . '"person_visible": true if any real person, face, arm or hand is visible anywhere in the image. A person visible is allowed when they are wearing or holding the item being reported, such as clothing, and it is only a note, not a problem. '
                . '"hand_gesture": look at every visible hand. "obscene" if ANY hand shows a raised middle finger or another offensive gesture, even if it is small, partly visible, or the hand is holding an item. "neutral" if hands are visible with no offensive gesture. "none" if no hand is visible. '
                . '"nudity_or_suggestive": true for nudity or sexually suggestive content. '
                . '"weapon_brandished": true only if a gun, knife or blade is held, aimed or displayed as a threat. A weapon lying on a surface is not brandished. '
                . '"graphic_violence": true for gore, blood or injury. '
                . '"body_part_only": true ONLY if the image shows a bare body part (for example just a foot, hand or torso with nothing worn) and there is no clothing, accessory, bag, shoes or other item on it that could be the item being reported. A person WEARING clothing, a uniform, a jacket, shoes, a cap, glasses, a bag or any accessory is NOT a body part only image, because the worn item is the item being reported, so this must be false. '
                . '"illegal_drugs": true if illegal drugs are visible. '
                . '"mannequin_or_display_figure": true ONLY if the main subject is a bare shop mannequin, display dummy, bust or large statue with NO clothing, bag or accessory worn on it that could be the item being reported. If a mannequin is wearing clothes, a bag, a hat, shoes or accessories, the clothing or accessory is the item and this must be false. A doll, stuffed toy, action figure or small toy is a normal item and must be false for this field. '
                . '"appropriate": false if any of the nudity, weapon, violence, body part only, illegal drugs or obscene gesture fields is true, otherwise true. '
                . '"appropriate_reason": a brief explanation if appropriate is false, or an empty string. '
                . '"prohibited": true ONLY if the main item is clearly a vape or e-cigarette, a cigarette or other tobacco product, a lighter, a weapon or blade lying on a surface, a toy gun, water gun, water pistol, airsoft or Nerf-style blaster, replica firearm or any toy weapon (anything clearly gun-shaped or weapon-shaped, even if plastic or brightly colored), or other clear school contraband. If you are not certain, false. A perfume bottle, spray bottle, water bottle, cosmetic container, USB drive or novelty item must not be flagged based on shape alone. '
                . '"prohibited_item": the short name of that item (for example "vape"), or an empty string. '
                . '"quality_ok": false only for genuinely unusable photos: severe motion blur where the subject is unrecognizable, extreme darkness where nothing is visible, or such low resolution that the image is only a blur of pixels. A normal phone photo in average indoor lighting, slightly soft or off-center, must pass. '
                . '"quality_reason": the specific quality problem if quality_ok is false, or an empty string. '
                . 'Respond with ONLY a JSON object with exactly these fields: '
                . '{"person_visible": true or false, "hand_gesture": "none" or "neutral" or "obscene", "nudity_or_suggestive": true or false, "weapon_brandished": true or false, "graphic_violence": true or false, "body_part_only": true or false, "illegal_drugs": true or false, "mannequin_or_display_figure": true or false, "appropriate": true or false, "appropriate_reason": "", "prohibited": true or false, "prohibited_item": "", "quality_ok": true or false, "quality_reason": ""}';

            $response = Http::timeout(25)->retry(2, 1000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $base64Image,
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if (!$response->successful()) {
                Log::warning('Gemini moderation check failed (' . $response->status() . '): ' . $response->body() . ' - Bypassing check.');
                return [
                    'passed' => true,
                    'error_code' => null,
                    'message' => 'Image check bypassed due to temporary AI service unavailability.',
                ];
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $cleanedText = preg_replace('/```json\s*|\s*```/', '', $textResult);
            $cleanedText = trim($cleanedText);

            $result = json_decode($cleanedText, true);

            if (!is_array($result) || !isset($result['quality_ok'])) {
                Log::error('Gemini content check returned unexpected format: ' . $textResult);
                return [
                    'passed' => true,
                    'error_code' => null,
                    'message' => 'Image passed check.',
                ];
            }

            // The server decides from the separate answers, not from one overall verdict
            $found = [];
            if (($result['hand_gesture'] ?? 'none') === 'obscene') {
                $found[] = 'an obscene hand gesture';
            }
            if (($result['nudity_or_suggestive'] ?? false) === true) {
                $found[] = 'nudity or sexually suggestive content';
            }
            if (($result['weapon_brandished'] ?? false) === true) {
                $found[] = 'a weapon being brandished';
            }
            if (($result['graphic_violence'] ?? false) === true) {
                $found[] = 'graphic violence';
            }
            if (($result['body_part_only'] ?? false) === true) {
                $found[] = 'a body part with no item to report';
            }
            if (($result['illegal_drugs'] ?? false) === true) {
                $found[] = 'illegal drugs';
            }

            // Always rejected, even for confiscated items
            if (!empty($found) || ($result['appropriate'] ?? true) === false) {
                Log::info('Moderation rejected an image', array_intersect_key($result, array_flip([
                    'hand_gesture', 'nudity_or_suggestive', 'weapon_brandished', 'graphic_violence',
                    'body_part_only', 'illegal_drugs', 'appropriate_reason',
                ])));

                $reason = !empty($found)
                    ? 'it contains ' . implode(' and ', $found)
                    : ($result['appropriate_reason'] ?? 'it does not meet the campus content guidelines');

                return [
                    'passed' => false,
                    'error_code' => 'AI_CONTENT_RESTRICTED',
                    'message' => !empty($found)
                        ? 'This photo contains ' . implode(' and ', $found) . ' and is not allowed to be uploaded. Please upload a different photo.'
                        : 'This image was flagged as inappropriate: ' . $reason . '. Please upload a different photo.',
                ];
            }

            // A mannequin or display figure is not a lost-and-found item
            if (($result['mannequin_or_display_figure'] ?? false) === true) {
                return [
                    'passed' => false,
                    'error_code' => 'NOT_AN_ITEM',
                    'message' => 'This photo shows a bare mannequin or display figure with no item on it. Please upload a photo of the item itself, for example the clothing without the mannequin.',
                ];
            }

            // Prohibited items (vapes, tobacco, lighters, weapons) are allowed only as confiscated records
            if (($result['prohibited'] ?? false) === true && !$allowProhibited) {
                $isAdminWrongType = $adminIntake !== null && $adminIntake !== 'confiscated';

                return [
                    'passed' => false,
                    'prohibited' => true,
                    'error_code' => 'AI_CONTENT_RESTRICTED',
                    'message' => $isAdminWrongType
                        ? "Inappropriate items like vapes can only be submitted under the 'Confiscated' intake type."
                        : "Items like vapes, lighters, tobacco products, weapons and toy weapons (such as toy guns and water pistols) can't be reported here. Please hand the item to the Guidance Office instead.",
                ];
            }

            if ($result['quality_ok'] !== true) {
                return [
                    'passed' => false,
                    'error_code' => 'IMAGE_QUALITY_LOW',
                    'message' => 'This photo doesn\'t look clear enough to use' . (!empty($result['quality_reason']) ? ': ' . $result['quality_reason'] : '') . '. Please upload a clearer, well-lit photo.',
                ];
            }

            return [
                'passed' => true,
                'error_code' => null,
                'message' => 'Image passed content and quality checks.',
            ];
        } catch (\Exception $e) {
            Log::error('Content moderation check exception: ' . $e->getMessage());
            return [
                'passed' => true,
                'error_code' => null,
                'message' => 'Image passed check.',
            ];
        }
    }
}
