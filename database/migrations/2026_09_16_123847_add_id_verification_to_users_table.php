<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('id_verified')->default(false)->after('password_last_changed_at');
            $table->string('id_photo_url')->nullable()->after('id_verified');
            $table->string('id_extracted_name')->nullable()->after('id_photo_url');
            $table->string('id_extracted_school_id')->nullable()->after('id_extracted_name');
            $table->string('identity_verification_status')->nullable()->after('id_extracted_school_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['id_verified', 'id_photo_url', 'id_extracted_name', 'id_extracted_school_id', 'identity_verification_status']);
        });
    }
};
