<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Своё название поиска и закрепление наверху «Моих поисков» и на инфопанели. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_searches', function (Blueprint $table) {
            $table->string('title', 80)->nullable();
            $table->timestampTz('pinned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listing_searches', function (Blueprint $table) {
            $table->dropColumn(['title', 'pinned_at']);
        });
    }
};
