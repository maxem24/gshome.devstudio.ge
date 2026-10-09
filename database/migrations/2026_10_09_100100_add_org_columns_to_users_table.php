<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('direction')->nullable();
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('both_directions')->default(false);
            $table->boolean('is_active')->default(true);
        });

        // Уже заведённые аккаунты (локальный админ) — владельцы: иначе NOT NULL
        // и CHECK ниже не прошли бы на существующих строках.
        DB::table('users')->whereNull('role')->update(['role' => 'owner']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable(false)->change();
        });

        // Инварианты оргструктуры — в базе, а не только в формах: ошибка в коде
        // не сохранит сотрудника без команды или владельца с компанией.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('owner','company_owner','team_lead','agent'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_direction_check CHECK (direction IS NULL OR direction IN ('sale','rent'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_owner_company_check CHECK ((role = 'owner') = (company_id IS NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_direction_check CHECK ((role IN ('team_lead','agent')) = (direction IS NOT NULL))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_team_lead_check CHECK ((role = 'agent') = (team_lead_id IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['users_role_check', 'users_direction_check', 'users_owner_company_check', 'users_team_direction_check', 'users_team_lead_check'] as $constraint) {
            DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_lead_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'direction', 'both_directions', 'is_active']);
        });
    }
};
