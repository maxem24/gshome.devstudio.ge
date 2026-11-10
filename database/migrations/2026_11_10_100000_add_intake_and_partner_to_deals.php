<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Анкета клиента: ключи и значения проверяет приложение, не база.
            $table->jsonb('intake')->default('{}');
            $table->decimal('price', 14, 2)->nullable();
            $table->string('price_currency', 3)->nullable();
            // Партнёр по сделке — другой сотрудник, делит с агентом комиссию.
            $table->foreignId('partner_user_id')->nullable()->constrained('users');
            $table->decimal('partner_share', 14, 2)->nullable();

            $table->index(['partner_user_id', 'status']);
        });

        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_partner_not_agent CHECK (partner_user_id IS NULL OR partner_user_id <> user_id)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_partner_share CHECK (partner_share IS NULL OR (partner_share > 0 AND amount IS NOT NULL AND partner_share < amount))');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_price_pair CHECK ((price IS NULL) = (price_currency IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_price_pair');
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_partner_share');
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_partner_not_agent');

        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['partner_user_id', 'status']);
            $table->dropConstrainedForeignId('partner_user_id');
            $table->dropColumn(['intake', 'price', 'price_currency', 'partner_share']);
        });
    }
};
