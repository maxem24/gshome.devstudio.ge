<?php

use App\Enums\ClientHome;
use App\Enums\ClientOccupant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('clients')->select('id', 'profile')->orderBy('id')->cursor() as $row) {
            $profile = is_array($row->profile) ? $row->profile : json_decode((string) $row->profile, true);
            if (! is_array($profile)) {
                continue;
            }

            $note = is_string($profile['note'] ?? null) ? $profile['note'] : '';
            $kept = [];

            if (is_string($profile['occupants'] ?? null) && ClientOccupant::tryFrom($profile['occupants']) === null) {
                $kept[] = $profile['occupants'];
                unset($profile['occupants']);
            }
            if (is_string($profile['home'] ?? null)) {
                if (ClientHome::tryFrom($profile['home']) !== null) {
                    $profile['home'] = [$profile['home']];
                } else {
                    $kept[] = $profile['home'];
                    unset($profile['home']);
                }
            }
            if (is_string($profile['place'] ?? null) && $profile['place'] !== '') {
                $kept[] = $profile['place'];
            }
            unset($profile['place']);

            if ($kept !== []) {
                $profile['note'] = trim($note."\n".implode("\n", $kept));
            }

            DB::table('clients')->where('id', $row->id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Списки не разворачиваются обратно в свободный текст.
    }
};
