<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Статус клиента: без отметки — активен (ищет квартиру); новые клиенты активны по умолчанию. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->whereNull('housing')->update(['housing' => 'looking']);
        DB::statement("ALTER TABLE clients ALTER COLUMN housing SET DEFAULT 'looking'");
    }

    /** Необратимо по данным: кто был без отметки, после up() неотличим от активных — NULL не вернуть. */
    public function down(): void
    {
        DB::statement('ALTER TABLE clients ALTER COLUMN housing DROP DEFAULT');
    }
};
