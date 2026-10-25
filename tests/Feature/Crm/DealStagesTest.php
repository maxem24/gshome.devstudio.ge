<?php

namespace Tests\Feature\Crm;

use App\Crm\DealStages;
use App\Crm\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DealStagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(fn ($s): array => [
            'key' => $s->key, 'labels' => $s->labels, 'hidden' => $s->hidden, 'kind' => $s->kind,
        ], DealStages::all());
    }

    public function test_defaults_when_not_configured(): void
    {
        $this->assertSame(
            ['call', 'viewing_scheduled', 'viewing_done', 'negotiation', 'won', 'lost'],
            array_map(fn ($s) => $s->key, DealStages::all()),
        );
        $this->assertSame('Созвон с клиентом', DealStages::find('call')->label('ru'));
        $this->assertSame('Call with client', DealStages::find('call')->label('en'));
        $this->assertSame('call', DealStages::first()->key);
        $this->assertSame('won', DealStages::find('won')->kind);
        $this->assertSame('lost', DealStages::find('lost')->kind);
    }

    public function test_label_falls_back_to_ru_when_locale_label_is_empty(): void
    {
        $rows = $this->rows();
        $rows[0]['labels']['en'] = '';
        DealStages::save($rows, User::factory()->owner()->create());

        $this->assertSame('Созвон с клиентом', DealStages::find('call')->label('en'));
    }

    public function test_save_keeps_keys_and_order(): void
    {
        $owner = User::factory()->owner()->create();
        $rows = $this->rows();
        $rows[0]['labels']['ru'] = 'Первый звонок';
        $rows[1]['hidden'] = true;
        $rows[] = ['key' => 'extra', 'labels' => ['ru' => 'Доп', 'en' => '', 'ka' => ''], 'hidden' => false, 'kind' => 'normal'];
        [$rows[2], $rows[3]] = [$rows[3], $rows[2]];

        DealStages::save($rows, $owner);

        $this->assertSame(
            ['call', 'viewing_scheduled', 'negotiation', 'viewing_done', 'won', 'lost', 'extra'],
            array_map(fn ($s) => $s->key, DealStages::all()),
        );
        $this->assertSame('Первый звонок', DealStages::find('call')->label('ru'));
        $this->assertSame(['call', 'negotiation', 'viewing_done', 'extra'], array_map(
            fn ($s) => $s->key,
            array_values(array_filter(DealStages::visible(), fn ($s) => $s->kind === 'normal')),
        ));
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_STAGES_SAVED)->count());
    }

    public function test_save_rejects_removing_a_key(): void
    {
        $rows = $this->rows();
        array_splice($rows, 1, 1);

        $this->expectException(InvalidArgumentException::class);
        DealStages::save($rows, User::factory()->owner()->create());
    }

    public function test_save_rejects_removing_a_key_after_saving(): void
    {
        $owner = User::factory()->owner()->create();
        $rows = $this->rows();
        $rows[] = ['key' => 'extra', 'labels' => ['ru' => 'Доп'], 'hidden' => false, 'kind' => 'normal'];
        DealStages::save($rows, $owner);

        $this->expectException(InvalidArgumentException::class);
        DealStages::save($this->rows_without('extra'), $owner);
    }

    /** @return list<array<string, mixed>> */
    private function rows_without(string $key): array
    {
        return array_values(array_filter($this->rows(), fn ($r) => $r['key'] !== $key));
    }

    public function test_save_requires_exactly_one_won_and_one_lost_visible(): void
    {
        $owner = User::factory()->owner()->create();

        $hiddenWon = $this->rows();
        $hiddenWon[4]['hidden'] = true;
        $twoWon = $this->rows();
        $twoWon[3]['kind'] = 'won';
        $noLost = $this->rows();
        $noLost[5]['kind'] = 'normal';

        foreach ([$hiddenWon, $twoWon, $noLost] as $rows) {
            try {
                DealStages::save($rows, $owner);
                $this->fail('Ожидалось исключение.');
            } catch (InvalidArgumentException) {
                $this->assertCount(6, DealStages::all());
            }
        }
        $this->assertSame(0, Activity::query()->where('event', Journal::DEAL_STAGES_SAVED)->count());
    }

    public function test_save_rejects_bad_and_duplicate_keys(): void
    {
        $owner = User::factory()->owner()->create();

        $bad = $this->rows();
        $bad[] = ['key' => 'Bad Key', 'labels' => ['ru' => 'x'], 'hidden' => false, 'kind' => 'normal'];
        $dup = $this->rows();
        $dup[] = $dup[0];

        foreach ([$bad, $dup] as $rows) {
            try {
                DealStages::save($rows, $owner);
                $this->fail('Ожидалось исключение.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_all_is_memoized_and_save_flushes_it(): void
    {
        $this->assertSame('call', DealStages::all()[0]->key);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        DealStages::all();
        DealStages::find('won');
        $this->assertSame(0, $queries);

        $rows = $this->rows();
        $rows[0]['labels']['ru'] = 'Новый звонок';
        DealStages::save($rows, User::factory()->owner()->create());
        $this->assertSame('Новый звонок', DealStages::find('call')->label('ru'));
    }
}
