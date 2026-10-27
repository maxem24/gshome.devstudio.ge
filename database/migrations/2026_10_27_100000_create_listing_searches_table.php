<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->jsonb('filters');
            $table->string('body_hash', 40);
            $table->uuid('remote_id')->nullable();
            $table->string('status', 15)->default('queued');
            $table->text('message')->nullable();
            $table->jsonb('sites')->nullable();
            $table->unsignedInteger('found')->default(0);
            $table->boolean('truncated')->default(false);
            $table->jsonb('listing_ids')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'body_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_searches');
    }
};
