<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Комнаты клиента: строка «2-3» → rooms_min / rooms_max, чтобы подбор сравнивал числа. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('clients')->whereRaw("jsonb_exists(profile, 'rooms')")->get(['id', 'profile']) as $row) {
            $profile = (array) json_decode((string) $row->profile, true);
            $raw = trim((string) $profile['rooms']);
            unset($profile['rooms']);

            if (preg_match('/^(\d{1,2})$/u', $raw, $m)) {
                $profile['rooms_min'] = $profile['rooms_max'] = (int) $m[1];
            } elseif (preg_match('/^(\d{1,2})\s*[-–—]\s*(\d{1,2})$/u', $raw, $m)) {
                $profile['rooms_min'] = (int) $m[1];
                $profile['rooms_max'] = (int) $m[2];
            } elseif (preg_match('/^(\d{1,2})\s*\+$/u', $raw, $m)) {
                $profile['rooms_min'] = (int) $m[1];
            } elseif (in_array(mb_strtolower($raw), ['студия', 'studio', 'სტუდიო'], true)) {
                $profile['rooms_min'] = $profile['rooms_max'] = 1;
            } elseif ($raw !== '') {
                $note = trim((string) ($profile['note'] ?? ''));
                $profile['note'] = ltrim($note."\nКомнат: ".$raw);
            }

            DB::table('clients')->where('id', $row->id)->update(['profile' => json_encode($profile, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('clients')->whereRaw("jsonb_exists_any(profile, array['rooms_min','rooms_max'])")->get(['id', 'profile']) as $row) {
            $profile = (array) json_decode((string) $row->profile, true);
            $min = $profile['rooms_min'] ?? null;
            $max = $profile['rooms_max'] ?? null;
            unset($profile['rooms_min'], $profile['rooms_max']);
            $profile['rooms'] = match (true) {
                $max === null => $min.'+',
                $min === null || $min === $max => (string) $max,
                default => $min.'-'.$max,
            };
            DB::table('clients')->where('id', $row->id)->update(['profile' => json_encode($profile, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
