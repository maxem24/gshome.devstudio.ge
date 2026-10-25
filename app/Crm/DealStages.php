<?php

namespace App\Crm;

use App\Models\CrmSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/** Этапы сделки: запись crm_settings['deal_stages'], а пока её нет — набор по умолчанию. */
final class DealStages
{
    public const SETTING_KEY = 'deal_stages';

    public const KINDS = ['normal', 'won', 'lost'];

    private const DEFAULTS = [
        'call' => 'normal',
        'viewing_scheduled' => 'normal',
        'viewing_done' => 'normal',
        'negotiation' => 'normal',
        'won' => 'won',
        'lost' => 'lost',
    ];

    /** @return list<DealStage> по порядку, включая скрытые */
    public static function all(): array
    {
        $rows = CrmSetting::query()->find(self::SETTING_KEY)?->value;
        $rows = is_array($rows) && $rows !== [] ? $rows : self::defaults();

        $stages = array_map(fn (array $r): DealStage => new DealStage(
            key: (string) $r['key'],
            labels: [
                'ru' => (string) ($r['labels']['ru'] ?? ''),
                'en' => (string) ($r['labels']['en'] ?? ''),
                'ka' => (string) ($r['labels']['ka'] ?? ''),
            ],
            order: (int) $r['order'],
            hidden: (bool) ($r['hidden'] ?? false),
            kind: (string) ($r['kind'] ?? 'normal'),
        ), array_values($rows));

        usort($stages, fn (DealStage $a, DealStage $b): int => $a->order <=> $b->order);

        return $stages;
    }

    /** @return list<DealStage> */
    public static function visible(): array
    {
        return array_values(array_filter(self::all(), fn (DealStage $s): bool => ! $s->hidden));
    }

    public static function find(string $key): ?DealStage
    {
        foreach (self::all() as $stage) {
            if ($stage->key === $key) {
                return $stage;
            }
        }

        return null;
    }

    /** Первый видимый обычный этап — с него начинается сделка. */
    public static function first(): DealStage
    {
        foreach (self::visible() as $stage) {
            if ($stage->kind === 'normal') {
                return $stage;
            }
        }

        throw new InvalidArgumentException('Нет ни одного видимого обычного этапа.');
    }

    /**
     * Порядок берётся из порядка строк. Ключи сохранённых этапов удалять нельзя.
     *
     * @param  list<array{key?: mixed, labels?: mixed, hidden?: mixed, kind?: mixed}>  $stages
     */
    public static function save(array $stages, User $by): void
    {
        $rows = [];
        foreach (array_values($stages) as $i => $s) {
            $key = (string) ($s['key'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Недопустимый ключ этапа: {$key}");
            }
            if (isset($rows[$key])) {
                throw new InvalidArgumentException("Ключ этапа повторяется: {$key}");
            }
            $kind = (string) ($s['kind'] ?? 'normal');
            if (! in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException("Недопустимый тип этапа: {$kind}");
            }
            $labels = is_array($s['labels'] ?? null) ? $s['labels'] : [];
            $labels = [
                'ru' => trim((string) ($labels['ru'] ?? '')),
                'en' => trim((string) ($labels['en'] ?? '')),
                'ka' => trim((string) ($labels['ka'] ?? '')),
            ];
            if ($labels['ru'] === '') {
                throw new InvalidArgumentException("У этапа {$key} нет русской подписи.");
            }
            $rows[$key] = [
                'key' => $key,
                'labels' => $labels,
                'order' => $i,
                'hidden' => (bool) ($s['hidden'] ?? false),
                'kind' => $kind,
            ];
        }

        $removed = array_diff(array_map(fn (DealStage $s): string => $s->key, self::all()), array_keys($rows));
        if ($removed !== []) {
            throw new InvalidArgumentException('Этапы удалять нельзя: '.implode(', ', $removed));
        }

        foreach (['won', 'lost'] as $kind) {
            $count = count(array_filter($rows, fn (array $r): bool => $r['kind'] === $kind && ! $r['hidden']));
            if ($count !== 1) {
                throw new InvalidArgumentException("Нужен ровно один видимый этап типа {$kind}.");
            }
        }
        if (count(array_filter($rows, fn (array $r): bool => $r['kind'] === 'normal' && ! $r['hidden'])) === 0) {
            throw new InvalidArgumentException('Нужен хотя бы один видимый обычный этап.');
        }

        DB::transaction(function () use ($rows, $by): void {
            CrmSetting::query()->updateOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => array_values($rows)],
            );
            Journal::record(Journal::DEAL_STAGES_SAVED, null, ['keys' => array_keys($rows)], $by);
        });
    }

    /** @return list<array<string, mixed>> */
    private static function defaults(): array
    {
        $rows = [];
        $i = 0;
        foreach (self::DEFAULTS as $key => $kind) {
            $rows[] = [
                'key' => $key,
                'labels' => [
                    'ru' => Lang::get("gshome.crm.stage.{$key}", [], 'ru'),
                    'en' => Lang::get("gshome.crm.stage.{$key}", [], 'en'),
                    'ka' => Lang::get("gshome.crm.stage.{$key}", [], 'ka'),
                ],
                'order' => $i++,
                'hidden' => false,
                'kind' => $kind,
            ];
        }

        return $rows;
    }
}
