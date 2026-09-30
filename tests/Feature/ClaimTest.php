<?php

namespace Tests\Feature;

use App\Models\AiMatch;
use App\Models\Claim;
use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use App\Models\OwnershipQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock Gemini responses for Question generation & matching
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'skip' => false,
                                        'questions' => [
                                            [
                                                'question' => 'What color is the zipper pull?',
                                                'option_a' => 'Silver metal',
                                                'option_b' => 'Black plastic',
                                                'option_c' => 'Red string',
                                                'option_d' => 'Yellow ribbon',
                                                'correct_option' => 'a'
                                            ]
                                        ]
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_student_can_submit_a_claim_for_a_matched_item(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $lostReport = LostItemReport::factory()->create(['user_id' => $student->id]);
        $foundRecord = FoundItemRecord::factory()->create([
            'admin_id' => $admin->id,
            'description' => 'A distinct blue backpack with silver metal zippers and initials JB.',
        ]);

        $match = AiMatch::factory()->create([
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
        ]);

        Sanctum::actingAs($student);

        $payload = [
            'match_id' => $match->id,
            'proof_description' => 'This is my backpack. Inside is my IT notebook and a scientific calculator.',
        ];

        $response = $this->postJson('/api/claims', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('claim.student_id', $student->id)
            ->assertJsonPath('claim.claim_status', 'pending');

        $this->assertDatabaseHas('claims', [
            'match_id' => $match->id,
            'student_id' => $student->id,
            'claim_status' => 'pending',
        ]);
    }

    public function test_submitting_duplicate_claim_returns_409(): void
    {
        $student = User::factory()->student()->create();
        $claim = Claim::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/claims', [
            'match_id' => $claim->match_id,
            'proof_description' => 'Submitting again',
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'You have already submitted a claim for this item']);
    }

    public function test_restricted_student_cannot_submit_claim(): void
    {
        $restrictedStudent = User::factory()->student()->restricted('Suspended claim privilege')->create();
        $match = AiMatch::factory()->create();

        Sanctum::actingAs($restrictedStudent);

        $response = $this->postJson('/api/claims', [
            'match_id' => $match->id,
            'proof_description' => 'Trying to claim while restricted',
        ]);

        $response->assertStatus(403);
    }

    public function test_student_can_fetch_and_answer_ownership_questions(): void
    {
        $student = User::factory()->student()->create();
        $claim = Claim::factory()->create(['student_id' => $student->id]);

        $question = OwnershipQuestion::factory()->create([
            'claim_id' => $claim->id,
            'question_text' => 'What is the sticker on the back of the laptop?',
            'option_a' => 'GitHub Octocat',
            'option_b' => 'Google Chrome',
            'option_c' => 'No sticker',
            'option_d' => 'Apple logo',
            'correct_option' => 'a',
        ]);

        Sanctum::actingAs($student);

        // Fetch questions
        $fetchResponse = $this->getJson("/api/claims/{$claim->id}/questions");
        $fetchResponse->assertStatus(200)
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.id', $question->id);

        // Submit answers
        $answerResponse = $this->postJson("/api/claims/{$claim->id}/answers", [
            'answers' => [
                [
                    'question_id' => $question->id,
                    'answer' => 'a',
                ]
            ]
        ]);

        $answerResponse->assertStatus(200)
            ->assertJsonPath('correct_count', 1);

        $question->refresh();
        $this->assertEquals('a', $question->student_answer);
    }

    public function test_admin_can_approve_a_claim(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();

        $lostReport = LostItemReport::factory()->create(['user_id' => $student->id]);
        $foundRecord = FoundItemRecord::factory()->create(['admin_id' => $admin->id]);
        $match = AiMatch::factory()->create([
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
        ]);

        $claim = Claim::factory()->create([
            'match_id' => $match->id,
            'student_id' => $student->id,
            'claim_status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/claims/{$claim->id}/approve", [
            'admin_notes' => 'Proof verified in person.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('claim.claim_status', 'approved');

        $claim->refresh();
        $this->assertEquals('approved', $claim->claim_status);
        $this->assertNotNull($claim->pickup_deadline);

        $lostReport->refresh();
        $foundRecord->refresh();
        $this->assertEquals('returned', $lostReport->status);
        $this->assertEquals('claimed', $foundRecord->status);
    }

    public function test_admin_can_reject_a_claim(): void
    {
        $admin = User::factory()->admin()->create();
        $claim = Claim::factory()->create(['claim_status' => 'pending']);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/claims/{$claim->id}/reject", [
            'admin_notes' => 'Answers to ownership questions were incorrect.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('claim.claim_status', 'rejected');

        $claim->refresh();
        $this->assertEquals('rejected', $claim->claim_status);
        $this->assertEquals('Answers to ownership questions were incorrect.', $claim->admin_notes);
    }

    public function test_student_can_appeal_a_rejected_claim(): void
    {
        $student = User::factory()->student()->create();
        $claim = Claim::factory()->rejected()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/claims/{$claim->id}/appeal", [
            'appeal_message' => 'I have receipts showing proof of purchase for this exact serial number.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('claim.appeal_status', 'pending');

        $claim->refresh();
        $this->assertEquals('pending', $claim->appeal_status);
        $this->assertNotNull($claim->appeal_submitted_at);
    }

    public function test_admin_can_resolve_an_appeal(): void
    {
        $admin = User::factory()->admin()->create();
        $claim = Claim::factory()->appealed()->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/claims/{$claim->id}/resolve-appeal", [
            'decision' => 'overturn',
            'resolution_notes' => 'Receipt was validated.',
        ]);

        $response->assertStatus(200);

        $claim->refresh();
        $this->assertEquals('approved', $claim->claim_status);
        $this->assertEquals('approved', $claim->appeal_status);
    }

    public function test_admin_can_mark_item_as_collected(): void
    {
        $admin = User::factory()->admin()->create();
        $foundRecord = FoundItemRecord::factory()->create();
        $match = AiMatch::factory()->create(['found_id' => $foundRecord->id]);
        $claim = Claim::factory()->approved($admin)->create(['match_id' => $match->id]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/claims/{$claim->id}/collected");

        $response->assertStatus(200)
            ->assertJsonPath('claim.claim_status', 'collected');

        $claim->refresh();
        $this->assertNotNull($claim->collected_at);
    }
}
