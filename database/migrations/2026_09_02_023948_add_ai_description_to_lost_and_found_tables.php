<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_item_reports', function (Blueprint $table) {
            $table->text('ai_description')->nullable()->after('description');
        });

        Schema::table('found_item_records', function (Blueprint $table) {
            $table->text('ai_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('lost_item_reports', function (Blueprint $table) {
            $table->dropColumn('ai_description');
        });

        Schema::table('found_item_records', function (Blueprint $table) {
            $table->dropColumn('ai_description');
        });
    }
};
