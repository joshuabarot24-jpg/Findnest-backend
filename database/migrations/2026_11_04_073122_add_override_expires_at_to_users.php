<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('override_expires_at')->nullable();
        });

        // Overrides already granted get 24 hours from now
        DB::table('users')
            ->where('manual_override_granted', true)
            ->update(['override_expires_at' => now()->addHours(24)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('override_expires_at');
        });
    }
};
