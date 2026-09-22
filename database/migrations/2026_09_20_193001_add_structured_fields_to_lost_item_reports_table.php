<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_item_reports', function (Blueprint $table) {
            $table->string('approx_time')->nullable()->after('date_lost');
            $table->string('primary_color')->nullable()->after('description');
            $table->string('brand_model')->nullable()->after('primary_color');
        });
    }
    public function down(): void
    {
        Schema::table('lost_item_reports', function (Blueprint $table) {
            $table->dropColumn(['approx_time', 'primary_color', 'brand_model']);
        });
    }
};
