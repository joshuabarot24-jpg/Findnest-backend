<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('lost_item_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('item_name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('location_lost');
            $table->date('date_lost');
            $table->string('photo_url')->nullable();
            $table->enum('status', ['searching', 'matched', 'claimed', 'returned'])->default('searching');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('lost_item_reports');
    }
};
