<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals');
            $table->timestampTz('scheduled_at');
            $table->text('notes')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->timestampTz('outcome_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('deal_id');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewings');
    }
};
