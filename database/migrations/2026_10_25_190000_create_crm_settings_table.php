<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->jsonb('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_settings');
    }
};
