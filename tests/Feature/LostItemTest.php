<?php

namespace Tests\Feature;

use App\Models\LostItemReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LostItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake any external AI/Gemini requests triggered by MatchScoreService
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"similarity_score": 85}']
                            ]
                        ]
                    ]
                ]
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_student_can_submit_a_lost_item_report(): void
    {
        $student = User::factory()->student()->create();
        Sanctum::actingAs($student);

        $payload = [
            'item_name' => 'Hydro Flask 32oz',
            'category' => 'Personal Belongings',
            'description' => 'White flask with stickers on the side',
            'location_lost' => 'Main Library 2nd Floor',
            'date_lost' => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '10:30 AM',
            'primary_color' => 'White',
            'brand_model' => 'Hydro Flask',
        ];

        $response = $this->postJson('/api/lost-items', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('report.item_name', 'Hydro Flask 32oz')
            ->assertJsonPath('report.status', 'searching')
            ->assertJsonPath('report.user_id', $student->id);

        $this->assertDatabaseHas('lost_item_reports', [
            'item_name' => 'Hydro Flask 32oz',
            'user_id' => $student->id,
            'status' => 'searching',
        ]);
    }

    public function test_lost_item_submission_fails_with_missing_required_fields(): void
    {
        $student = User::factory()->student()->create();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/lost-items', [
            'description' => 'Just some notes',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_name', 'category', 'location_lost', 'date_lost']);
    }

    public function test_lost_item_date_must_be_within_the_last_two_days(): void
    {
        $student = User::factory()->student()->create();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/lost-items', [
            'item_name' => 'Notebook',
            'category' => 'Documents',
            'location_lost' => 'Gymnasium',
            'date_lost' => Carbon::now('Asia/Manila')->subDays(5)->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_lost']);
    }

    public function test_student_cannot_exceed_five_active_searching_reports(): void
    {
        $student = User::factory()->student()->create();
        Sanctum::actingAs($student);

        LostItemReport::factory()->count(5)->create([
            'user_id' => $student->id,
            'status' => 'searching',
        ]);

        $response = $this->postJson('/api/lost-items', [
            'item_name' => '6th Item',
            'category' => 'Accessories',
            'location_lost' => 'Canteen',
            'date_lost' => Carbon::now('Asia/Manila')->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You have reached the limit of 5 active lost item reports. Please wait for an existing report to be resolved before submitting a new one.',
            ]);
    }

    public function test_student_can_fetch_own_reports(): void
    {
        $studentA = User::factory()->student()->create();
        $studentB = User::factory()->student()->create();

        LostItemReport::factory()->count(2)->create(['user_id' => $studentA->id]);
        LostItemReport::factory()->count(3)->create(['user_id' => $studentB->id]);

        Sanctum::actingAs($studentA);

        $response = $this->getJson('/api/lost-items/my-reports');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'reports');
    }

    public function test_user_can_view_specific_lost_item_report(): void
    {
        $student = User::factory()->student()->create();
        $report = LostItemReport::factory()->create([
            'user_id' => $student->id,
            'item_name' => 'Calculus Book',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/lost-items/{$report->id}");

        $response->assertStatus(200)
            ->assertJsonPath('report.id', $report->id)
            ->assertJsonPath('report.item_name', 'Calculus Book');
    }

    public function test_user_can_update_lost_item_report(): void
    {
        $student = User::factory()->student()->create();
        $report = LostItemReport::factory()->create([
            'user_id' => $student->id,
            'item_name' => 'Old Title',
        ]);

        Sanctum::actingAs($student);

        $response = $this->putJson("/api/lost-items/{$report->id}", [
            'item_name' => 'Updated Title',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('report.item_name', 'Updated Title');

        $this->assertDatabaseHas('lost_item_reports', [
            'id' => $report->id,
            'item_name' => 'Updated Title',
        ]);
    }

    public function test_user_can_delete_lost_item_report(): void
    {
        $student = User::factory()->student()->create();
        $report = LostItemReport::factory()->create(['user_id' => $student->id]);

        Sanctum::actingAs($student);

        $response = $this->deleteJson("/api/lost-items/{$report->id}", [
            'reason' => 'Found it in my bag',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Report deleted successfully']);

        $this->assertDatabaseMissing('lost_item_reports', [
            'id' => $report->id,
        ]);
    }
}
