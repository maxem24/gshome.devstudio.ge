<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_pin_id')->constrained('listing_pins');
            $table->foreignId('client_id')->constrained('clients');
            // Агент сделки — держатель закрепления; при передаче закрепления переходит к новому.
            $table->foreignId('user_id')->constrained('users');
            $table->string('stage', 50);
            $table->string('status', 15)->default('open');
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('lost_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['listing_pin_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_status_check CHECK (status IN ('open', 'won', 'lost', 'unavailable'))");
        // Один клиент — одна сделка на закрепление среди неархивных.
        DB::statement('CREATE UNIQUE INDEX deals_pin_client_unique ON deals (listing_pin_id, client_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
