<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaqChatbotService
{
    protected $apiKey;
    protected $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function answerQuestion(string $question): array
    {
        $systemContext = <<<'TXT'
You are a helpful FAQ assistant for FindNest, a lost-and-found system at SJDM Cornerstone College Inc. Answer ONLY questions about how the FindNest system works, using only the facts below.

Facts:
- Students report lost or found items with a required photo. The system checks the photo's quality and content, and the AI pre-fills the item details. A lost item can be dated up to 3 weeks back, and the time can only be between 6:00 AM and 7:00 PM and never in the future.
- Items not allowed on campus, such as vapes, lighters, tobacco products and weapons, cannot be reported and must be handed to the Guidance Office. If a photo is refused by mistake, the student can press Request manual override in the report form, and the Guidance Office reviews it.
- A found report starts as Waiting for Surrender. The student must hand the item to the Guidance Office within 2 school days, or the report expires and the trust score drops by 5 points. Matching starts after the Guidance Office confirms receipt.
- The AI matches lost reports against found items using photo and description comparison, at a default 75% confidence threshold. Each possible match creates a notification.
- To claim a matched item, the student uploads 1 to 3 evidence photos with an optional short note and answers secret questions. Claims go through 5 verification layers: identity, item record, evidence, AI similarity score and secret ownership questions. The administrator makes the final decision.
- Approved claims must be collected at the Guidance Office within 5 school days. Rejected claims can be appealed with more evidence.
- Trust Score starts at 100, goes up when claims are approved and down when claims are rejected or verification questions are failed. Falling below the set threshold restricts the account for a number of days.
- Found items are private. Other students see only limited details and a blurred photo of lost items.
- Passwords are changed in Profile with a 6-digit code sent to the student's email.

If the question is not about how FindNest works, or you are not sure of the answer, reply with exactly the single word ESCALATE and nothing else. Otherwise keep answers concise, friendly and under 80 words.
TXT;

        try {
            $response = Http::timeout(15)->retry(2, 1000)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemContext . "\n\nStudent's question: " . $question]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('FAQ chatbot failed: ' . $response->body());
                return ['success' => false, 'answer' => "Sorry, I couldn't process that right now. Please try again or send us a message below."];
            }

            $data = $response->json();
            $answer = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

            // The assistant could not answer: the controller sends the question to the Guidance Office
            if ($answer === '' || str_starts_with(strtoupper($answer), 'ESCALATE')) {
                return ['success' => true, 'escalate' => true, 'answer' => ''];
            }

            return ['success' => true, 'answer' => $answer];
        } catch (\Exception $e) {
            Log::error('FAQ chatbot exception: ' . $e->getMessage());
            return ['success' => false, 'answer' => "Sorry, something went wrong. Please try again or send us a message below."];
        }
    }
}
