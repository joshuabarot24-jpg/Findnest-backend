<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->timestamp('surrender_deadline')->nullable();
            $table->boolean('receipt_confirmed')->default(true);
            $table->timestamp('receipt_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->dropColumn(['surrender_deadline', 'receipt_confirmed', 'receipt_confirmed_at']);
        });
    }
};
