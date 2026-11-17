<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"guy\"') WHERE profile->>'occupants' = 'husband'");
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"girl\"') WHERE profile->>'occupants' = 'wife'");
    }

    public function down(): void
    {
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"husband\"') WHERE profile->>'occupants' = 'guy'");
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"wife\"') WHERE profile->>'occupants' = 'girl'");
    }
};
