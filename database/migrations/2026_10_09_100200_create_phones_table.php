<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phones', function (Blueprint $table) {
            $table->id();
            // E.164: один номер — одна строка во всей системе.
            $table->string('number', 20)->unique();
            $table->string('kind');
            // Номер принадлежит компании; сотрудник — текущий держатель.
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE phones ADD CONSTRAINT phones_kind_check CHECK (kind IN ('personal','office'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('phones');
    }
};
