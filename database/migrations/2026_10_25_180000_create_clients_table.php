<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            // Уникален среди непустых, включая архивные: архивный клиент держит номер.
            $table->string('phone_e164')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('reliability')->default('unverified');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
