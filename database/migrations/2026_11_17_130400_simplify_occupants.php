<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $toOne = ['caller', 'guy', 'girl', 'husband', 'wife', 'one_guy'];
        $list = implode(', ', array_map(fn (string $key): string => "'".$key."'", $toOne));
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"one_person\"') WHERE profile->>'occupants' IN ($list)");
        DB::statement("UPDATE clients SET profile = jsonb_set(profile, '{occupants}', '\"small_family\"') WHERE profile->>'occupants' = 'family'");

        foreach (DB::table('clients')->select('id', 'profile')->orderBy('id')->cursor() as $row) {
            $profile = is_array($row->profile) ? $row->profile : json_decode((string) $row->profile, true);
            if (! is_array($profile) || ! in_array($profile['occupants'] ?? null, ['brother', 'sister', 'child'], true)) {
                continue;
            }
            $note = trim((string) ($profile['note'] ?? ''));
            $profile['note'] = trim($note."\n".$profile['occupants']);
            unset($profile['occupants']);
            DB::table('clients')->where('id', $row->id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Старые отдельные роли не восстанавливаются.
    }
};
