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
        $systemContext = "You are a helpful FAQ assistant for FindNest, a lost-and-found system at SJDM Cornerstone College Inc. Answer ONLY questions about how the FindNest system works. Students report lost or found items with a required photo. AI auto-fills item details and pre-fills descriptions. AI matches lost reports against found items using photo and description comparison, at a default 75% confidence threshold. When matched, students get notified and can submit a claim with a description and optional evidence photos. Claims go through 5 verification layers: identity, item record, evidence, AI similarity score, and secret ownership questions. Admin makes the final approve/reject decision. Approved claims must be picked up from the Guidance Office within a set number of school days, or the claim is marked abandoned. Rejected claims can be appealed with more evidence, reviewed by an admin. Students have a Trust Score starting at 100 that goes up when claims are approved and down when claims are rejected or verification questions are failed; falling below 50 temporarily restricts claim submissions. Found items are only shown to the student they are matched to, not publicly browsable. If the student's question is not about how FindNest works, politely say you cannot help with that and suggest they send a message to the Guidance Office using the message box below instead. Keep answers concise, friendly, and under 80 words.";

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
            $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Sorry, I couldn't generate an answer. Please try again.";

            return ['success' => true, 'answer' => trim($answer)];
        } catch (\Exception $e) {
            Log::error('FAQ chatbot exception: ' . $e->getMessage());
            return ['success' => false, 'answer' => "Sorry, something went wrong. Please try again or send us a message below."];
        }
    }
}
