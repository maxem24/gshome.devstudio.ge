<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Договор с собственником живёт на участии агента: у каждого свои условия. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->timestampTz('agreed_at')->nullable();
            $table->foreignId('agreed_by')->nullable()->constrained('users');
            $table->decimal('agreed_price', 12, 2)->nullable();
            $table->string('agreed_currency', 3)->nullable();
            $table->decimal('owner_price', 12, 2)->nullable();
            $table->string('commission_kind', 20)->nullable();
            $table->unsignedSmallInteger('commission_percent')->nullable();
            $table->unsignedSmallInteger('commission_months')->nullable();
            $table->decimal('commission_amount', 12, 2)->nullable();
            $table->string('commission_note', 100)->nullable();
            $table->date('available_from')->nullable();
            $table->unsignedSmallInteger('min_term_months')->nullable();
            $table->unsignedBigInteger('myhome_id')->nullable();
            $table->unsignedBigInteger('ss_id')->nullable();
            $table->text('agreement_note')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE listing_pins ADD CONSTRAINT listing_pins_agreement_check CHECK (
                ((agreed_at IS NULL) = (agreed_by IS NULL))
                AND ((agreed_at IS NULL) = (agreed_price IS NULL))
                AND ((agreed_at IS NULL) = (agreed_currency IS NULL))
                AND ((agreed_at IS NULL) = (commission_kind IS NULL))
                AND (agreed_currency IS NULL OR agreed_currency IN ('GEL', 'USD'))
                AND (commission_kind IS NULL OR commission_kind IN ('percent', 'fixed', 'percent_months', 'other'))
                AND (commission_percent IS NULL OR commission_percent BETWEEN 1 AND 100)
                AND (min_term_months IS NULL OR min_term_months IN (6, 12))
                AND (
                    commission_kind IS NULL
                    OR (commission_kind = 'percent' AND commission_percent IS NOT NULL AND commission_months IS NULL AND commission_amount IS NULL AND commission_note IS NULL)
                    OR (commission_kind = 'fixed' AND commission_amount IS NOT NULL AND commission_percent IS NULL AND commission_months IS NULL AND commission_note IS NULL)
                    OR (commission_kind = 'percent_months' AND commission_percent IS NOT NULL AND commission_months IS NOT NULL AND commission_amount IS NULL AND commission_note IS NULL)
                    OR (commission_kind = 'other' AND commission_note IS NOT NULL AND commission_percent IS NULL AND commission_months IS NULL AND commission_amount IS NULL)
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE listing_pins DROP CONSTRAINT IF EXISTS listing_pins_agreement_check');
        Schema::table('listing_pins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreed_by');
            $table->dropColumn([
                'agreed_at', 'agreed_price', 'agreed_currency', 'owner_price', 'commission_kind',
                'commission_percent', 'commission_months', 'commission_amount', 'commission_note',
                'available_from', 'min_term_months', 'myhome_id', 'ss_id', 'agreement_note',
            ]);
        });
    }
};
