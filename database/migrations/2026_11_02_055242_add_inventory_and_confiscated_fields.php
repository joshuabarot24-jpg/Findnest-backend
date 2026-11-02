<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate stock records for the Others / Inventory tab
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('location')->nullable();
            $table->string('status')->default('in_stock');
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Extra details for confiscated items
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->string('student_name')->nullable();
            $table->text('violation_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->dropColumn(['student_name', 'violation_notes']);
        });
        Schema::dropIfExists('inventory_items');
    }
};
