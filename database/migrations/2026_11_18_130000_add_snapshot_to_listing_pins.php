<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Снимок объявления на объекте в работе: по нему подбор пар считается без запросов к API. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->smallInteger('rooms')->nullable();
            $table->integer('subdistrict_id')->nullable();
            $table->string('title')->nullable();
            $table->timestampTz('snapshot_at')->nullable();
        });
        DB::statement("CREATE INDEX listing_pins_rent_active_idx ON listing_pins (id) WHERE status = 'active' AND direction = 'rent' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listing_pins_rent_active_idx');
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->dropColumn(['price_usd', 'rooms', 'subdistrict_id', 'title', 'snapshot_at']);
        });
    }
};
