<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Enums\ClientHome;
use App\Enums\ClientOccupant;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Подбор пар «объект аренды в работе ↔ активный клиент» одним SQL-запросом.
 * Правило живёт в SQL: по каждому критерию запрос отдаёт known (обе стороны заполнили) и hit (0…1);
 * PHP только переводит строку в текст «что не сходится». Дизайн — specs/2026-11-18-landlord-client-matching-design.md.
 */
final class Matching
{
    public const WEIGHTS = ['place' => 30, 'price' => 25, 'occupants' => 20, 'pets' => 10, 'rooms' => 10, 'home' => 5, 'people' => 5];

    /** Меньше этой суммы весов известных критериев — «мало данных», такие пары ниже остальных. */
    public const MIN_KNOWN = 40;

    /** @return list<MatchRow> клиенты для объекта; $other — Client */
    public function clientsFor(ListingPin $pin, int $limit = 10): array
    {
        $rows = $this->query('p.id = ?', [$pin->id], 'c.id', $limit);
        $clients = Client::query()->findMany(array_column($rows, 'client_id'))->keyBy('id');

        return $this->rows($rows, fn (object $r): ?Client => $clients->get($r->client_id));
    }

    /** @return list<MatchRow> объекты для клиента; $other — ListingPin с держателем */
    public function pinsFor(Client $client, User $viewer, int $limit = 10): array
    {
        if (! in_array(Direction::Rent, OrgAccess::directions($viewer), true)) {
            return [];
        }
        $rows = $this->query('c.id = ?', [$client->id], 'p.id', $limit, $viewer);
        $pins = ListingPin::query()->with(['holder', 'object'])->findMany(array_column($rows, 'pin_id'))->keyBy('id');

        return $this->rows($rows, fn (object $r): ?ListingPin => $pins->get($r->pin_id));
    }

    /**
     * @param  list<int>  $bindings
     * @return list<object>
     */
    private function query(string $side, array $bindings, string $tieBreak, int $limit, ?User $viewer = null): array
    {
        // Сторона клиента: одна строка на объект — участие смотрящего, иначе самое старшее активное.
        $pins = 'listing_pins';
        if ($viewer !== null) {
            $pins = "(SELECT DISTINCT ON (q.object_id) q.* FROM listing_pins q
                WHERE q.status = 'active' AND q.direction = 'rent' AND q.deleted_at IS NULL
                ORDER BY q.object_id, (q.user_id = ".(int) $viewer->id.') DESC, q.id)';
        }

        $num = fn (string $json): string => "(CASE WHEN jsonb_typeof({$json}) = 'number' THEN ({$json})::numeric END)";
        $arr = fn (string $json): string => "(CASE WHEN jsonb_typeof({$json}) = 'array' THEN {$json} ELSE '[]'::jsonb END)";
        $filled = fn (string $json): string => "(jsonb_array_length({$arr($json)}) > 0)";

        $cmin = $num("c.profile->'price_min'");
        $cmax = $num("c.profile->'price_max'");
        $rmin = $num("c.profile->'rooms_min'");
        $rmax = $num("c.profile->'rooms_max'");
        $cHome = $arr("c.profile->'home'");
        $lHome = $arr("p.preferences->'home'");
        $opposite = "(CASE e.v WHEN 'furnished' THEN ARRAY['unfurnished'] WHEN 'unfurnished' THEN ARRAY['furnished']
            WHEN 'new_build' THEN ARRAY['old_building'] WHEN 'old_building' THEN ARRAY['new_build']
            WHEN 'renovated' THEN ARRAY['old_renovation','no_renovation'] WHEN 'old_renovation' THEN ARRAY['renovated','no_renovation']
            WHEN 'no_renovation' THEN ARRAY['renovated','old_renovation'] ELSE ARRAY[]::text[] END)";
        $homeItems = "jsonb_array_elements_text({$cHome}) AS e(v) WHERE e.v <> 'pets'";

        $pairs = <<<SQL
            SELECT c.id AS client_id, p.id AS pin_id, o.rooms AS pin_rooms, c.profile->>'occupants' AS occupant,
                COALESCE((o.subdistrict_id IS NOT NULL AND {$filled("c.profile->'subdistrict_ids'")}), false)::int AS known_place,
                (c.profile->'subdistrict_ids' @> to_jsonb(o.subdistrict_id))::int AS hit_place,
                COALESCE((o.price_usd IS NOT NULL AND ({$cmin} IS NOT NULL OR {$cmax} IS NOT NULL)), false)::int AS known_price,
                GREATEST(o.price_usd - {$cmax}, 0) AS price_over,
                GREATEST({$cmin} - o.price_usd, 0) AS price_under,
                {$cmax} AS cmax, {$cmin} AS cmin,
                COALESCE((jsonb_typeof(c.profile->'occupants') = 'string' AND {$filled("p.preferences->'occupants'")}), false)::int AS known_occupants,
                jsonb_exists({$arr("p.preferences->'occupants'")}, c.profile->>'occupants')::int AS hit_occupants,
                COALESCE(({$filled("c.profile->'home'")} AND {$filled("p.preferences->'home'")}), false)::int AS known_pets,
                (NOT jsonb_exists({$cHome}, 'pets') OR jsonb_exists({$lHome}, 'pets'))::int AS hit_pets,
                COALESCE((o.rooms IS NOT NULL AND ({$rmin} IS NOT NULL OR {$rmax} IS NOT NULL)), false)::int AS known_rooms,
                GREATEST(COALESCE({$rmin} - o.rooms, 0), COALESCE(o.rooms - {$rmax}, 0), 0) AS rooms_off,
                (SELECT count(*) FROM {$homeItems} AND jsonb_exists({$lHome}, e.v)) AS home_hit,
                (SELECT jsonb_agg(e.v) FROM {$homeItems} AND NOT jsonb_exists({$lHome}, e.v) AND jsonb_exists_any({$lHome}, {$opposite})) AS home_missed,
                COALESCE(({$num("c.profile->'people'")} IS NOT NULL AND {$num("p.preferences->'people'")} IS NOT NULL), false)::int AS known_people,
                ({$num("c.profile->'people'")} <= {$num("p.preferences->'people'")})::int AS hit_people
            FROM clients c CROSS JOIN {$pins} p
            JOIN listing_objects o ON o.id = p.object_id AND o.status = 'open'
            WHERE c.housing = 'looking' AND c.deleted_at IS NULL
              AND p.status = 'active' AND p.direction = 'rent' AND p.deleted_at IS NULL
              AND {$side}
            SQL;

        $w = self::WEIGHTS;
        $scored = <<<SQL
            SELECT m.*, h.*,
                CASE WHEN m.price_over = 0 AND m.price_under = 0 THEN 1
                     WHEN (m.price_over > 0 AND m.price_over <= m.cmax * 0.10) OR (m.price_under > 0 AND m.price_under <= m.cmin * 0.10) THEN 0.5
                     ELSE 0 END AS hit_price,
                CASE m.rooms_off WHEN 0 THEN 1 WHEN 1 THEN 0.5 ELSE 0 END AS hit_rooms
            FROM ({$pairs}) m
            CROSS JOIN LATERAL (SELECT
                ((m.home_hit + COALESCE(jsonb_array_length(m.home_missed), 0)) > 0)::int AS known_home,
                m.home_hit::numeric / NULLIF(m.home_hit + COALESCE(jsonb_array_length(m.home_missed), 0), 0) AS hit_home
            ) h
            SQL;

        $knownSum = "{$w['place']} * known_place + {$w['price']} * known_price + {$w['occupants']} * known_occupants
            + {$w['pets']} * known_pets + {$w['rooms']} * known_rooms + {$w['home']} * known_home + {$w['people']} * known_people";
        $earned = "{$w['place']} * known_place * COALESCE(hit_place, 0) + {$w['price']} * known_price * COALESCE(hit_price, 0)
            + {$w['occupants']} * known_occupants * COALESCE(hit_occupants, 0) + {$w['pets']} * known_pets * COALESCE(hit_pets, 0)
            + {$w['rooms']} * known_rooms * COALESCE(hit_rooms, 0) + {$w['home']} * known_home * COALESCE(hit_home, 0)
            + {$w['people']} * known_people * COALESCE(hit_people, 0)";
        $tie = str_replace(['c.', 'p.'], ['client_', 'pin_'], $tieBreak);
        $min = self::MIN_KNOWN;

        return DB::select(<<<SQL
            SELECT s.*, ROUND(100 * ({$earned}) / NULLIF({$knownSum}, 0))::int AS score, (({$knownSum}) >= {$min}) AS enough
            FROM ({$scored}) s
            ORDER BY enough DESC, score DESC NULLS LAST, {$tie} DESC
            LIMIT {$limit}
            SQL, $bindings);
    }

    /**
     * @param  list<object>  $rows
     * @param  \Closure(object): (Client|ListingPin|null)  $other
     * @return list<MatchRow>
     */
    private function rows(array $rows, \Closure $other): array
    {
        $out = [];
        foreach ($rows as $r) {
            $model = $other($r);
            if ($model !== null) {
                $out[] = new MatchRow($model, $r->score === null ? null : (int) $r->score, (bool) $r->enough, $this->mismatches($r));
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function mismatches(object $r): array
    {
        $t = fn (string $key, array $args = []): string => __('gshome.crm.matching.'.$key, $args);
        $out = [];
        if ($r->known_place && ! $r->hit_place) {
            $out[] = $t('place');
        }
        if ($r->known_price && (float) $r->price_over > 0) {
            $out[] = $t('price_more', ['n' => (int) round((float) $r->price_over)]);
        }
        if ($r->known_price && (float) $r->price_under > 0) {
            $out[] = $t('price_less', ['n' => (int) round((float) $r->price_under)]);
        }
        if ($r->known_occupants && ! $r->hit_occupants) {
            $out[] = $t('occupants', ['who' => ClientOccupant::tryFrom((string) $r->occupant)?->getLabel() ?? (string) $r->occupant]);
        }
        if ($r->known_pets && ! $r->hit_pets) {
            $out[] = $t('pets');
        }
        if ($r->known_rooms && (int) $r->rooms_off > 0) {
            $out[] = $t('rooms', ['n' => (int) $r->pin_rooms]);
        }
        $missed = is_string($r->home_missed) ? (array) json_decode($r->home_missed, true) : [];
        if ($missed !== []) {
            $out[] = $t('home', ['what' => implode(', ', array_map(
                fn (mixed $v): string => ClientHome::tryFrom((string) $v)?->getLabel() ?? (string) $v,
                $missed,
            ))]);
        }
        if ($r->known_people && ! $r->hit_people) {
            $out[] = $t('people');
        }

        return $out;
    }
}
