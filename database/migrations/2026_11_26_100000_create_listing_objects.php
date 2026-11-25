<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Объект — общая карточка группы дублей; закрепления (listing_pins) становятся участиями агентов в ней.
 * Снимок на пинах и старый уникальный индекс пока остаются: их уберут следующими задачами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_objects', function (Blueprint $table) {
            $table->id();
            // «c:{cluster_id}» или «l:{listing_id}».
            $table->string('object_key', 40);
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('cluster_id')->nullable();
            $table->string('direction', 10);
            $table->string('status', 10)->default('open');
            $table->string('closed_reason', 20)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->foreignId('won_deal_id')->nullable()->constrained('deals');
            $table->foreignId('merged_into_id')->nullable()->constrained('listing_objects');
            // Снимок объявления: по нему подбор пар считается без запросов к API.
            $table->decimal('price_usd', 12, 2)->nullable();
            $table->decimal('price_gel', 12, 2)->nullable();
            $table->smallInteger('rooms')->nullable();
            $table->integer('subdistrict_id')->nullable();
            $table->string('title')->nullable();
            $table->timestampTz('snapshot_at')->nullable();
            $table->timestampsTz();

            $table->index('listing_id');
        });

        DB::statement("ALTER TABLE listing_objects ADD CONSTRAINT listing_objects_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement("ALTER TABLE listing_objects ADD CONSTRAINT listing_objects_reason_check CHECK ((status = 'open') = (closed_reason IS NULL))");
        DB::statement("ALTER TABLE listing_objects ADD CONSTRAINT listing_objects_reason_values_check CHECK (closed_reason IS NULL OR closed_reason IN ('won', 'rented_elsewhere', 'fake', 'abandoned', 'merged_into'))");
        // Один открытый объект на группу дублей.
        DB::statement("CREATE UNIQUE INDEX listing_objects_open_key ON listing_objects (object_key) WHERE status = 'open'");

        Schema::table('listing_pins', function (Blueprint $table) {
            $table->foreignId('object_id')->nullable()->constrained('listing_objects');
            $table->string('unavailable_reason', 20)->nullable();
        });

        DB::statement("ALTER TABLE listing_pins ADD CONSTRAINT listing_pins_unavailable_check CHECK (unavailable_reason IS NULL OR COALESCE(closed_reason, '') = 'unavailable')");
        // Один активный пин на агента в объекте (старый индекс по pin_key пока работает).
        DB::statement("CREATE UNIQUE INDEX listing_pins_active_agent ON listing_pins (object_id, user_id) WHERE status = 'active' AND deleted_at IS NULL");

        $this->backfill();
    }

    /** Каждому пину — свой объект; открытым остаётся только живой активный пин (ключ уникален). */
    private function backfill(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE listing_objects ADD COLUMN src_pin_id bigint
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO listing_objects
                (object_key, listing_id, cluster_id, direction, status, closed_reason, closed_at,
                 price_usd, rooms, subdistrict_id, title, snapshot_at, created_at, updated_at, src_pin_id)
            SELECT pin_key, listing_id, cluster_id, direction,
                   CASE WHEN status = 'active' AND deleted_at IS NULL THEN 'open' ELSE 'closed' END,
                   CASE WHEN status = 'active' AND deleted_at IS NULL THEN NULL
                        WHEN closed_reason = 'won' THEN 'won'
                        ELSE 'abandoned' END,
                   CASE WHEN status = 'active' AND deleted_at IS NULL THEN NULL
                        ELSE COALESCE(closed_at, deleted_at, updated_at) END,
                   price_usd, rooms, subdistrict_id, title, snapshot_at, created_at, updated_at, id
            FROM listing_pins
            SQL);
        DB::statement('UPDATE listing_pins p SET object_id = o.id FROM listing_objects o WHERE o.src_pin_id = p.id');
        DB::statement('ALTER TABLE listing_objects DROP COLUMN src_pin_id');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listing_pins_active_agent');
        DB::statement('ALTER TABLE listing_pins DROP CONSTRAINT IF EXISTS listing_pins_unavailable_check');
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('object_id');
            $table->dropColumn('unavailable_reason');
        });
        Schema::dropIfExists('listing_objects');
    }
};
