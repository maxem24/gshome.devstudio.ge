<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_pins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('cluster_id')->nullable();
            // «c:{cluster_id}» или «l:{listing_id}» — фиксируется при взятии.
            $table->string('pin_key', 40);
            $table->string('direction', 10);
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 10)->default('active');
            $table->string('closed_reason', 20)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('listing_id');
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE listing_pins ADD CONSTRAINT listing_pins_status_check CHECK (status IN ('active', 'closed'))");
        DB::statement("ALTER TABLE listing_pins ADD CONSTRAINT listing_pins_closed_check CHECK ((status = 'active') = (closed_reason IS NULL))");
        // Одно активное закрепление на группу дублей — держит база, не только код.
        DB::statement("CREATE UNIQUE INDEX listing_pins_active_key ON listing_pins (pin_key) WHERE status = 'active' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_pins');
    }
};
