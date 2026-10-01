<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE found_item_records DROP CONSTRAINT found_item_records_status_check');
        DB::statement("ALTER TABLE found_item_records ADD CONSTRAINT found_item_records_status_check CHECK (status::text = ANY (ARRAY['unclaimed'::character varying, 'matched'::character varying, 'claimed'::character varying, 'disposed'::character varying, 'confiscated'::character varying, 'others'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE found_item_records DROP CONSTRAINT found_item_records_status_check');
        DB::statement("ALTER TABLE found_item_records ADD CONSTRAINT found_item_records_status_check CHECK (status::text = ANY (ARRAY['unclaimed'::character varying, 'matched'::character varying, 'claimed'::character varying, 'disposed'::character varying]::text[]))");
    }
};
