<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Кандидаты подбора пар — активные клиенты не в архиве. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX clients_looking_idx ON clients (id) WHERE housing = 'looking' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clients_looking_idx');
    }
};
