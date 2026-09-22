<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_change_requested')->default(false)->after('trust_score');
            $table->text('password_change_reason')->nullable()->after('password_change_requested');
            $table->timestamp('password_last_changed_at')->nullable()->after('password_change_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_change_requested', 'password_change_reason', 'password_last_changed_at']);
        });
    }
};
