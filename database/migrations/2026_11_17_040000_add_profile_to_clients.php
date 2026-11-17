<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->jsonb('profile')->default('{}');
        });

        // «Кто будет жить» спрашивали в анкете сделки. Это ответ о человеке, не о квартире.
        DB::statement(<<<'SQL'
            UPDATE clients AS c
            SET profile = jsonb_strip_nulls(jsonb_build_object('occupants', sub.occupants))
            FROM (
                SELECT DISTINCT ON (client_id) client_id, intake->>'occupants' AS occupants
                FROM deals
                WHERE coalesce(intake->>'occupants', '') <> ''
                ORDER BY client_id, updated_at DESC
            ) AS sub
            WHERE c.id = sub.client_id
        SQL);
        // «?» у jsonb Laravel считает плейсхолдером, поэтому оператор записан функцией.
        DB::statement("UPDATE deals SET intake = intake - 'occupants' WHERE jsonb_exists(intake, 'occupants')");
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE deals AS d
            SET intake = d.intake || jsonb_build_object('occupants', c.profile->>'occupants')
            FROM clients AS c
            WHERE d.client_id = c.id
              AND coalesce(c.profile->>'occupants', '') <> ''
              AND NOT jsonb_exists(d.intake, 'occupants')
        SQL);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
