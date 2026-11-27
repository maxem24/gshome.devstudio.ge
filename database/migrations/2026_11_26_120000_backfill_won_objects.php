<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Объекты, закрытые «won» до появления won_deal_id/closed_by: берём выигранную сделку с одного из их пинов.
 * closed_by для старых строк приблизительный: агент выигранной сделки.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE listing_objects o
            SET won_deal_id = COALESCE(o.won_deal_id, w.id), closed_by = COALESCE(o.closed_by, w.user_id)
            FROM (
                SELECT DISTINCT ON (p.object_id) p.object_id, d.id, d.user_id
                FROM deals d
                JOIN listing_pins p ON p.id = d.listing_pin_id
                WHERE d.status = 'won' AND d.deleted_at IS NULL
                ORDER BY p.object_id, d.id
            ) w
            WHERE o.id = w.object_id
              AND o.closed_reason = 'won'
              AND (o.won_deal_id IS NULL OR o.closed_by IS NULL)
        SQL);
    }

    public function down(): void
    {
        // Ничего не откатываем: заполненные значения верны, а различить их с проставленными кодом нельзя.
    }
};
