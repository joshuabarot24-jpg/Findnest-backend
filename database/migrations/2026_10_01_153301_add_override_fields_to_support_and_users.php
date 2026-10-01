<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->boolean('is_override_request')->default(false);
            $table->string('override_status')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('manual_override_granted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn(['is_override_request', 'override_status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('manual_override_granted');
        });
    }
};
