<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_a_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('company_b_id')->constrained('companies')->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['company_a_id', 'company_b_id']);
        });

        // Пара хранится упорядоченно: A–B и B–A физически одна строка.
        DB::statement('ALTER TABLE company_exchanges ADD CONSTRAINT company_exchanges_order_check CHECK (company_a_id < company_b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_exchanges');
    }
};
