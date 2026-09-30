<?php

namespace Tests\Feature;

use App\Models\AiMatch;
use App\Models\OwnershipQuestion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Fake all external AI requests
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'similarity_score' => 90,
                                        'skip' => false,
                                        'questions' => [
                                            [
                                                'question' => 'What is the color of the protective skin on the laptop?',
                                                'option_a' => 'Matte Black',
                                                'option_b' => 'Clear plastic',
                                                'option_c' => 'Leather sleeve',
                                                'option_d' => 'No skin',
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

    public function test_complete_lost_and_found_lifecycle_from_report_to_collection(): void
    {
        // =========================================================================
        // Step 1: Student Login with OTP
        // =========================================================================
        $student = User::factory()->student()->create([
            'school_id' => '2024-10001',
            'email' => 'student.lifecycle@findnest.edu',
            'password' => Hash::make('StudentSecret!123'),
        ]);

        $otpResponse = $this->postJson('/api/auth/student/login', [
            'school_id' => '2024-10001',
            'password' => 'StudentSecret!123',
        ]);
        $otpResponse->assertStatus(200);

        $student->refresh();
        $otpCode = $student->otp_code;
        $this->assertNotEmpty($otpCode);

        $verifyResponse = $this->postJson('/api/auth/student/verify-otp', [
            'school_id' => '2024-10001',
            'otp' => $otpCode,
        ]);
        $verifyResponse->assertStatus(200);
        $studentToken = $verifyResponse->json('token');
        $this->assertNotEmpty($studentToken);

        $studentHeaders = ['Authorization' => 'Bearer ' . $studentToken];

        // =========================================================================
        // Step 2: Student Reports a Lost Item
        // =========================================================================
        $lostPayload = [
            'item_name' => 'Space Gray MacBook Air M2',
            'category' => 'Electronics',
            'description' => 'Laptop with matte black protective skin and sticker on the lid.',
            'location_lost' => 'College Library 2nd Floor',
            'date_lost' => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '02:30 PM',
            'primary_color' => 'Space Gray',
            'brand_model' => 'Apple MacBook Air',
        ];

        $lostResponse = $this->withHeaders($studentHeaders)->postJson('/api/lost-items', $lostPayload);
        $lostResponse->assertStatus(201);
        $lostReportId = $lostResponse->json('report.id');
        $this->assertNotNull($lostReportId);

        // =========================================================================
        // Step 3: Admin Logs In & Records a Found Item
        // =========================================================================
        $admin = User::factory()->admin()->create([
            'email' => 'admin.lifecycle@findnest.edu',
            'password' => Hash::make('AdminSecret!123'),
        ]);

        $adminLoginResponse = $this->postJson('/api/auth/admin/login', [
            'email' => 'admin.lifecycle@findnest.edu',
            'password' => 'AdminSecret!123',
        ]);
        $adminLoginResponse->assertStatus(200);
        $adminToken = $adminLoginResponse->json('token');
        $this->assertNotEmpty($adminToken);

        $adminHeaders = ['Authorization' => 'Bearer ' . $adminToken];

        $foundPayload = [
            'item_name' => 'Apple MacBook Laptop',
            'category' => 'Electronics',
            'description' => 'Dark gray laptop found left behind on a study desk. Has a matte skin.',
            'location_found' => 'College Library 2nd Floor',
            'date_found' => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '03:15 PM',
            'primary_color' => 'Space Gray',
            'brand_model' => 'Apple MacBook',
            'storage_location' => 'Guidance Office Locker 04',
        ];

        $foundResponse = $this->withHeaders($adminHeaders)->postJson('/api/found-items', $foundPayload);
        $foundResponse->assertStatus(201);
        $foundRecordId = $foundResponse->json('record.id');
        $this->assertNotNull($foundRecordId);

        // =========================================================================
        // Step 4: AI Match is generated / confirmed
        // =========================================================================
        $match = AiMatch::where('report_id', $lostReportId)
            ->where('found_id', $foundRecordId)
            ->first();

        if (!$match) {
            // If background scoring did not trigger automatically, create match via API
            $matchResponse = $this->withHeaders($adminHeaders)->postJson('/api/ai-matches', [
                'report_id' => $lostReportId,
                'found_id' => $foundRecordId,
                'confidence_score' => 92,
            ]);
            $matchResponse->assertStatus(201);
            $matchId = $matchResponse->json('match.id');
        } else {
            $matchId = $match->id;
        }

        // =========================================================================
        // Step 5: Student Files Claim for the Matched Item
        // =========================================================================
        $claimPayload = [
            'match_id' => $matchId,
            'proof_description' => 'I have the original Apple receipt and login credentials for this Mac.',
        ];

        $claimResponse = $this->withHeaders($studentHeaders)->postJson('/api/claims', $claimPayload);
        $claimResponse->assertStatus(201);
        $claimId = $claimResponse->json('claim.id');
        $this->assertNotNull($claimId);

        // Seed or verify ownership question for verification
        $question = OwnershipQuestion::firstOrCreate(
            ['claim_id' => $claimId],
            [
                'question_text' => 'What is the color of the protective skin?',
                'option_a' => 'Matte Black',
                'option_b' => 'Clear plastic',
                'option_c' => 'Leather sleeve',
                'option_d' => 'No skin',
                'correct_option' => 'a',
            ]
        );

        // Student submits answers
        $answerResponse = $this->withHeaders($studentHeaders)->postJson("/api/claims/{$claimId}/answers", [
            'answers' => [
                [
                    'question_id' => $question->id,
                    'answer' => 'a',
                ]
            ]
        ]);
        $answerResponse->assertStatus(200);

        // =========================================================================
        // Step 6: Admin Approves the Claim
        // =========================================================================
        $approveResponse = $this->withHeaders($adminHeaders)->postJson("/api/claims/{$claimId}/approve", [
            'admin_notes' => 'Serial number and ownership verified.',
        ]);
        $approveResponse->assertStatus(200)
            ->assertJsonPath('claim.claim_status', 'approved');

        // =========================================================================
        // Step 7: Student Visits Office & Admin Marks Item as Collected
        // =========================================================================
        $collectedResponse = $this->withHeaders($adminHeaders)->postJson("/api/claims/{$claimId}/collected");
        $collectedResponse->assertStatus(200)
            ->assertJsonPath('claim.claim_status', 'collected');

        // Final Assertions on the Database State
        $this->assertDatabaseHas('claims', [
            'id' => $claimId,
            'claim_status' => 'collected',
        ]);

        $this->assertDatabaseHas('lost_item_reports', [
            'id' => $lostReportId,
            'status' => 'returned',
        ]);

        $this->assertDatabaseHas('found_item_records', [
            'id' => $foundRecordId,
            'status' => 'claimed',
        ]);
    }
}
