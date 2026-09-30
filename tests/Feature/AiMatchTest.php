<?php

namespace Tests\Feature;

use App\Models\AiMatch;
use App\Models\FoundItemRecord;
use App\Models\LostItemReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_an_ai_match_between_lost_and_found_items(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();

        $lostReport = LostItemReport::factory()->create(['user_id' => $student->id]);
        $foundRecord = FoundItemRecord::factory()->create(['admin_id' => $admin->id]);

        Sanctum::actingAs($admin);

        $payload = [
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
            'confidence_score' => 88,
            'attributes' => [
                'description_score' => 90,
                'category_score' => 100,
            ],
        ];

        $response = $this->postJson('/api/ai-matches', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('match.confidence_score', 88)
            ->assertJsonPath('match.match_status', 'pending');

        $this->assertDatabaseHas('ai_matches', [
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
            'confidence_score' => 88,
        ]);

        $lostReport->refresh();
        $foundRecord->refresh();
        $this->assertEquals('matched', $lostReport->status);
        $this->assertEquals('matched', $foundRecord->status);
    }

    public function test_creating_duplicate_match_returns_409(): void
    {
        $match = AiMatch::factory()->create();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/ai-matches', [
            'report_id' => $match->report_id,
            'found_id' => $match->found_id,
            'confidence_score' => 80,
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Match already exists']);
    }

    public function test_student_can_fetch_their_matches(): void
    {
        $student = User::factory()->student()->create();
        $lostReport = LostItemReport::factory()->create(['user_id' => $student->id]);

        AiMatch::factory()->create([
            'report_id' => $lostReport->id,
            'confidence_score' => 85,
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/ai-matches/my-matches');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.confidence_score', 85);
    }

    public function test_student_can_reveal_their_matched_item_details(): void
    {
        $student = User::factory()->student()->create();
        $lostReport = LostItemReport::factory()->create(['user_id' => $student->id]);
        $foundRecord = FoundItemRecord::factory()->create(['item_name' => 'Found Umbrella']);

        $match = AiMatch::factory()->create([
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
            'confidence_score' => 92,
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/ai-matches/{$match->id}/reveal");

        $response->assertStatus(200)
            ->assertJsonPath('match.found_item.item_name', 'Found Umbrella');
    }

    public function test_unauthorized_student_cannot_reveal_another_students_matched_item(): void
    {
        $ownerStudent = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $lostReport = LostItemReport::factory()->create(['user_id' => $ownerStudent->id]);

        $match = AiMatch::factory()->create([
            'report_id' => $lostReport->id,
        ]);

        Sanctum::actingAs($otherStudent);

        $response = $this->getJson("/api/ai-matches/{$match->id}/reveal");

        $response->assertStatus(403);
    }

    public function test_confirm_match_updates_status(): void
    {
        $match = AiMatch::factory()->create(['match_status' => 'pending']);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ai-matches/{$match->id}/confirm");

        $response->assertStatus(200)
            ->assertJsonPath('match.match_status', 'confirmed');

        $match->refresh();
        $this->assertEquals('confirmed', $match->match_status);
    }

    public function test_reject_match_restores_item_statuses_to_searching_and_unclaimed(): void
    {
        $lostReport = LostItemReport::factory()->create(['status' => 'matched']);
        $foundRecord = FoundItemRecord::factory()->create(['status' => 'matched']);

        $match = AiMatch::factory()->create([
            'report_id' => $lostReport->id,
            'found_id' => $foundRecord->id,
            'match_status' => 'pending',
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/ai-matches/{$match->id}/reject");

        $response->assertStatus(200)
            ->assertJsonPath('match.match_status', 'rejected');

        $lostReport->refresh();
        $foundRecord->refresh();
        $this->assertEquals('searching', $lostReport->status);
        $this->assertEquals('unclaimed', $foundRecord->status);
    }
}
