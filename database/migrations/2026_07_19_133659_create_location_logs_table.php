<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('lost_item_reports')->onDelete('cascade');
            $table->string('building');
            $table->string('area');
            $table->float('latitude')->nullable();
            $table->float('longitude')->nullable();
            $table->enum('type', ['lost', 'found']);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('location_logs');
    }
};
