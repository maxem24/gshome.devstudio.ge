<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал отправленных напоминаний: по нему команда не шлёт одно и то же дважды.
        Schema::create('viewing_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewing_id')->constrained('viewings');
            $table->string('kind', 20);
            $table->date('sent_on');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['viewing_id', 'kind', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_reminders');
    }
};
