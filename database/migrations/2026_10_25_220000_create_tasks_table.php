<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->foreignId('assignee_id')->constrained('users');
            $table->foreignId('author_id')->constrained('users');
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 15)->default('open');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
