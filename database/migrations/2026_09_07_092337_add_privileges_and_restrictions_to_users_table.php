<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('privileges')->nullable()->after('is_active');
            $table->boolean('is_restricted')->default(false)->after('privileges');
            $table->text('restriction_reason')->nullable()->after('is_restricted');
            $table->date('restricted_until')->nullable()->after('restriction_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privileges', 'is_restricted', 'restriction_reason', 'restricted_until']);
        });
    }
};
