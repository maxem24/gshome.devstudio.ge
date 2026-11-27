<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Участие вместо эксклюзивного закрепления: у объекта может быть несколько активных пинов,
 * поэтому уникальность по pin_key уходит, а связь с объектом становится обязательной.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS listing_pins_active_key');
        DB::statement('ALTER TABLE listing_pins ALTER COLUMN object_id SET NOT NULL');
        // NULL остаётся для активного пина: listing_pins_closed_check уже связывает NULL со статусом active.
        DB::statement("ALTER TABLE listing_pins ADD CONSTRAINT listing_pins_closed_reason_check CHECK (closed_reason IS NULL OR closed_reason IN ('won', 'unavailable', 'released', 'closed_by_other', 'merged'))");
    }

    /**
     * Откат упадёт, когда несколько агентов делят один объект: уникальный индекс по pin_key
     * больше не соблюдается. Так задумано — данные не склеиваем молча.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE listing_pins DROP CONSTRAINT IF EXISTS listing_pins_closed_reason_check');
        DB::statement('ALTER TABLE listing_pins ALTER COLUMN object_id DROP NOT NULL');
        DB::statement("CREATE UNIQUE INDEX listing_pins_active_key ON listing_pins (pin_key) WHERE status = 'active' AND deleted_at IS NULL");
    }
};
