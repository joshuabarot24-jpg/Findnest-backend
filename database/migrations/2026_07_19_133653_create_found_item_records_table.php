<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('found_item_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->string('item_name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('location_found');
            $table->date('date_found');
            $table->string('photo_url')->nullable();
            $table->string('storage_location')->nullable();
            $table->enum('status', ['unclaimed', 'matched', 'claimed', 'disposed'])->default('unclaimed');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('found_item_records');
    }
};
