<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Лента фильтрует по событию, журнал — по автору; обе сортируются по времени.
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index(['event', 'created_at'], 'activity_log_event_created_at_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_event_created_at_index');
            $table->dropIndex('activity_log_causer_created_at_index');
        });
    }
};
