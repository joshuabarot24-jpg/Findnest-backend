<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ItemDescriptionService
{
    protected $apiKey;
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function analyzeImage(string $imageUrl, ?string $itemHint = null): array
    {
        try {
            $imageContent = file_get_contents($imageUrl);
            $base64Image = base64_encode($imageContent);

            $promptText = $itemHint
                ? 'Look at this image carefully. The user has specifically identified that they want details about: "' . $itemHint . '". Focus ONLY on that specific item, ignoring any other items also visible in the frame. The item may be worn on the body or attached to something (e.g. a watch on a wrist, a ring on a finger, earbuds in hand). Generate a structured description AND a short, specific item name (e.g. "Black Nike Backpack", "Silver Apple Watch") under 6 words. Respond with ONLY a JSON object in this exact format, no other text, no markdown: {"item_detected": true, "item_name": "short specific item name", "category": "one of: Electronics, Personal Belongings, ID/Cards, Keys, School Supplies, Accessories, Others", "primary_color": "string or empty", "secondary_color": "string or empty", "brand_or_markings": "string or empty", "materials": "string or empty", "distinctive_features": "string or empty", "summary": "one paragraph description"}'
                : 'Look at this image carefully. First, determine if it shows one or more physical, identifiable lost-and-found type items (such as electronics, wallets, bags, clothing, accessories, keys, ID cards, school supplies, water bottles, etc). The item(s) may be worn on a person\'s body or attached to something (e.g. a watch on a wrist, a ring on a finger, earbuds in hand, an ID on a lanyard) — these still count as valid identifiable items; focus on the items, not the person. Screenshots, selfies with no item focus, documents, or unrelated random photos do NOT count. List EVERY distinct identifiable item you can clearly see, even if there are several (for example, a watch, a ring, and earbuds together would be 3 separate items). If the image shows a cluttered background with an excessive number of unrelated items (roughly 15 or more), treat this as too cluttered to process and set too_cluttered to true instead of listing everything. Respond with ONLY a JSON object in this exact format, no other text, no markdown: {"item_detected": true or false, "too_cluttered": true or false, "items_found": ["short name of item 1", "short name of item 2"], "item_name": "short specific item name if only ONE item was found, or empty string if zero, multiple, or cluttered", "category": "one of: Electronics, Personal Belongings, ID/Cards, Keys, School Supplies, Accessories, Others, or empty string", "primary_color": "string or empty", "secondary_color": "string or empty", "brand_or_markings": "string or empty", "materials": "string or empty", "distinctive_features": "string or empty", "summary": "one paragraph description, only filled if exactly one item was found"}';

            $response = Http::timeout(20)->retry(3, 2000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $promptText],
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
                Log::error('Item description generation failed: ' . $response->body());
                return [
                    'success' => false,
                    'item_detected' => false,
                    'message' => 'We could not analyze this image right now. Please try again in a moment.',
                ];
            }

            $data = $response->json();
            $textResult = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $cleanedText = preg_replace('/```json\s*|\s*```/', '', $textResult);
            $cleanedText = trim($cleanedText);

            $result = json_decode($cleanedText, true);

            if (!is_array($result) || !isset($result['item_detected'])) {
                Log::error('Item description returned unexpected format: ' . $textResult);
                return [
                    'success' => false,
                    'item_detected' => false,
                    'message' => 'We could not analyze this image right now. Please try again in a moment.',
                ];
            }

            if (($result['too_cluttered'] ?? false) === true) {
                return [
                    'success' => true,
                    'item_detected' => false,
                    'message' => 'This photo shows too many items to identify clearly. Please upload a photo focused on fewer items.',
                ];
            }

            if ($result['item_detected'] !== true) {
                return [
                    'success' => true,
                    'item_detected' => false,
                    'message' => 'We could not identify a lost-and-found item in this photo. Please upload a clear photo of the actual item.',
                ];
            }

            $itemsFound = $result['items_found'] ?? [];
            if (!$itemHint && count($itemsFound) > 1) {
                return [
                    'success' => true,
                    'item_detected' => false,
                    'multiple_items' => true,
                    'items_found' => $itemsFound,
                    'message' => 'We detected multiple items in this photo. Please select which one you are reporting.',
                ];
            }

            return [
                'success' => true,
                'item_detected' => true,
                'item_name' => $result['item_name'] ?? '',
                'category' => $result['category'] ?? '',
                'ai_description' => $result['summary'] ?? '',
                'details' => [
                    'primary_color' => $result['primary_color'] ?? '',
                    'secondary_color' => $result['secondary_color'] ?? '',
                    'brand_or_markings' => $result['brand_or_markings'] ?? '',
                    'materials' => $result['materials'] ?? '',
                    'distinctive_features' => $result['distinctive_features'] ?? '',
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Item description generation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'item_detected' => false,
                'message' => 'We could not analyze this image right now. Please try again in a moment.',
            ];
        }
    }
}
