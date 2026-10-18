<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Id объявления в Real Estate, не строка нашей базы.
            $table->unsignedBigInteger('listing_id');
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
        });

        DB::statement('ALTER TABLE saved_listings ADD CONSTRAINT saved_listings_listing_id_check CHECK (listing_id > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_listings');
    }
};
