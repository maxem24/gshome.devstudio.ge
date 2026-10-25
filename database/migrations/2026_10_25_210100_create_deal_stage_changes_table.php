<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals');
            $table->string('from_stage', 50)->nullable();
            $table->string('to_stage', 50);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestampTz('changed_at');

            $table->index('deal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_changes');
    }
};
