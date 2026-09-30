<?php

namespace Tests\Feature;

use App\Models\FoundItemRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoundItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_admin_can_record_a_found_item(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $payload = [
            'item_name' => 'Black Leather Wallet',
            'category' => 'Personal Belongings',
            'description' => 'Contains student ID and some receipts',
            'location_found' => 'College Canteen Table 4',
            'date_found' => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'approx_time' => '11:00 AM',
            'primary_color' => 'Black',
            'storage_location' => 'Guidance Office Box A',
        ];

        $response = $this->postJson('/api/found-items', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('record.item_name', 'Black Leather Wallet')
            ->assertJsonPath('record.status', 'unclaimed')
            ->assertJsonPath('record.receipt_confirmed', true);

        $this->assertDatabaseHas('found_item_records', [
            'item_name' => 'Black Leather Wallet',
            'admin_id' => $admin->id,
            'status' => 'unclaimed',
        ]);
    }

    public function test_admin_blocked_from_item_management_cannot_access_found_items(): void
    {
        $restrictedAdmin = User::factory()->admin(['item_management'])->create();
        Sanctum::actingAs($restrictedAdmin);

        $response = $this->getJson('/api/found-items');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have access to this page. Contact the Super Admin if you believe this is a mistake.',
            ]);
    }

    public function test_found_item_validation_fails_when_required_fields_missing(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/found-items', [
            'description' => 'Just random notes without required info',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_name', 'category', 'location_found', 'date_found']);
    }

    public function test_admin_can_list_found_items(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        FoundItemRecord::factory()->count(3)->create(['admin_id' => $admin->id]);

        $response = $this->getJson('/api/found-items');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'records');
    }

    public function test_admin_can_view_single_found_item(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $record = FoundItemRecord::factory()->create([
            'admin_id' => $admin->id,
            'item_name' => 'Scientific Calculator Casio fx-991EX',
        ]);

        $response = $this->getJson("/api/found-items/{$record->id}");

        $response->assertStatus(200)
            ->assertJsonPath('record.id', $record->id)
            ->assertJsonPath('record.item_name', 'Scientific Calculator Casio fx-991EX');
    }

    public function test_admin_can_update_found_item(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $record = FoundItemRecord::factory()->create(['admin_id' => $admin->id]);

        $response = $this->putJson("/api/found-items/{$record->id}", [
            'item_name' => 'Updated Found Item Name',
            'storage_location' => 'Guidance Office Cabinet 2',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('record.item_name', 'Updated Found Item Name');

        $this->assertDatabaseHas('found_item_records', [
            'id' => $record->id,
            'item_name' => 'Updated Found Item Name',
            'storage_location' => 'Guidance Office Cabinet 2',
        ]);
    }

    public function test_admin_can_confirm_receipt_of_surrendered_item(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $record = FoundItemRecord::factory()->pendingReceipt()->create([
            'admin_id' => $admin->id,
        ]);

        $this->assertFalse($record->receipt_confirmed);

        $response = $this->postJson("/api/found-items/{$record->id}/confirm-receipt");

        $response->assertStatus(200)
            ->assertJsonPath('record.receipt_confirmed', true);

        $record->refresh();
        $this->assertTrue($record->receipt_confirmed);
        $this->assertNotNull($record->receipt_confirmed_at);
        $this->assertNull($record->surrender_deadline);
    }

    public function test_admin_can_document_disposal_of_unclaimed_item(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $record = FoundItemRecord::factory()->unclaimed()->create(['admin_id' => $admin->id]);

        $response = $this->postJson("/api/found-items/{$record->id}/document-disposal", [
            'disposal_notes' => 'Donated to student affairs after 90 days retention period.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('record.status', 'disposed');

        $record->refresh();
        $this->assertEquals('disposed', $record->status);
        $this->assertEquals('Donated to student affairs after 90 days retention period.', $record->disposal_notes);
        $this->assertNotNull($record->disposed_at);
    }

    public function test_admin_can_delete_found_item_record(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $record = FoundItemRecord::factory()->create(['admin_id' => $admin->id]);

        $response = $this->deleteJson("/api/found-items/{$record->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Record deleted successfully']);

        $this->assertDatabaseMissing('found_item_records', [
            'id' => $record->id,
        ]);
    }
}
