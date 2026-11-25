<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Снимок объявления живёт только на объекте: колонки на пинах больше никто не читает. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS listing_pins_rent_active_idx');
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->dropColumn(['price_usd', 'rooms', 'subdistrict_id', 'title', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->smallInteger('rooms')->nullable();
            $table->integer('subdistrict_id')->nullable();
            $table->string('title')->nullable();
            $table->timestampTz('snapshot_at')->nullable();
        });
        // Значения — с объекта пина.
        DB::statement('UPDATE listing_pins p SET price_usd = o.price_usd, rooms = o.rooms, subdistrict_id = o.subdistrict_id,
            title = o.title, snapshot_at = o.snapshot_at FROM listing_objects o WHERE o.id = p.object_id');
        DB::statement("CREATE INDEX listing_pins_rent_active_idx ON listing_pins (id) WHERE status = 'active' AND direction = 'rent' AND deleted_at IS NULL");
    }
};
