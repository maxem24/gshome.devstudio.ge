<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг is_active не различал «временно заблокирован» и «ушёл совсем».
 * Выключенные до этой миграции — уволенные (FireUser), то есть архив.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active');
        });

        DB::table('users')->where('is_active', false)->update(['status' => 'archived']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','blocked','archived'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        // Блокировка в прежней схеме не выражается — заблокированный остаётся активным.
        DB::table('users')->where('status', 'archived')->update(['is_active' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
