<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE found_item_records DROP CONSTRAINT IF EXISTS found_item_records_status_check');
        DB::statement("ALTER TABLE found_item_records ADD CONSTRAINT found_item_records_status_check CHECK (status IN ('unclaimed','claimed','for_disposal','disposed','confiscated','found_item','others','waiting_for_surrender','unsurrendered_expired'))");
    }

    public function down(): void
    {
        // intentionally left empty
    }
};
