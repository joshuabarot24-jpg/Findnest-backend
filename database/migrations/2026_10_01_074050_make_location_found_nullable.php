<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->string('location_found')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('found_item_records', function (Blueprint $table) {
            $table->string('location_found')->nullable(false)->change();
        });
    }
};
