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
            $table->renameColumn('school_id', 'lrn');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 50)->nullable();
            $table->string('last_name', 50)->nullable();
            $table->string('middle_initial', 5)->nullable();
            $table->string('suffix', 10)->nullable();
        });

        // Fill the new name columns from the current full names
        $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
        foreach (DB::table('users')->select('id', 'name')->get() as $u) {
            $parts = preg_split('/\s+/', trim((string) $u->name));
            $suffix = null;
            if (count($parts) > 2 && in_array(strtolower(end($parts)), $suffixes, true)) {
                $suffix = array_pop($parts);
            }
            $last = count($parts) > 1 ? array_pop($parts) : null;
            $first = implode(' ', $parts);

            DB::table('users')->where('id', $u->id)->update([
                'first_name' => $first ?: null,
                'last_name' => $last,
                'suffix' => $suffix,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'middle_initial', 'suffix']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('lrn', 'school_id');
        });
    }
};
