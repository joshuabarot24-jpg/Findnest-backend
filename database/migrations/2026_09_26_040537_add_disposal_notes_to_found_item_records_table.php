<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->text('disposal_notes')->nullable();
            $table->timestamp('disposed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->dropColumn(['disposal_notes', 'disposed_at']);
        });
    }
};
