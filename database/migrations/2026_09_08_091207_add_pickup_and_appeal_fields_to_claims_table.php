<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->date('pickup_deadline')->nullable()->after('claimed_at');
            $table->timestamp('collected_at')->nullable()->after('pickup_deadline');
            $table->boolean('reminder_sent')->default(false)->after('collected_at');
            $table->text('appeal_message')->nullable()->after('reminder_sent');
            $table->string('appeal_status')->nullable()->after('appeal_message');
            $table->timestamp('appeal_submitted_at')->nullable()->after('appeal_status');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['pickup_deadline', 'collected_at', 'reminder_sent', 'appeal_message', 'appeal_status', 'appeal_submitted_at']);
        });
    }
};
