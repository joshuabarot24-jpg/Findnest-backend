<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('lost_item_reports')->onDelete('cascade');
            $table->foreignId('found_id')->constrained('found_item_records')->onDelete('cascade');
            $table->float('confidence_score');
            $table->json('attributes')->nullable();
            $table->enum('match_status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('ai_matches');
    }
};
