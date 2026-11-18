<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Кому собственник готов сдать: тот же список, что «Кого ищет» у клиента. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->jsonb('preferences')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
