<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN locale SET DEFAULT 'ka'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN locale SET DEFAULT 'ru'");
    }
};
