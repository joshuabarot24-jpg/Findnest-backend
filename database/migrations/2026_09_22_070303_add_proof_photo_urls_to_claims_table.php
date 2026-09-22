<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->json('proof_photo_urls')->nullable()->after('proof_photo_url');
        });
    }
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('proof_photo_urls');
        });
    }
};
