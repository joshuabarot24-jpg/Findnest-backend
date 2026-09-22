<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_matches', function (Blueprint $table) {
            $table->boolean('reverse_engineering_flag')->default(false)->after('match_status');
        });

        Schema::table('found_item_records', function (Blueprint $table) {
            $table->timestamp('unclaimed_flagged_at')->nullable()->after('status');
            $table->boolean('needs_disposal_review')->default(false)->after('unclaimed_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_matches', function (Blueprint $table) {
            $table->dropColumn('reverse_engineering_flag');
        });

        Schema::table('found_item_records', function (Blueprint $table) {
            $table->dropColumn(['unclaimed_flagged_at', 'needs_disposal_review']);
        });
    }
};
