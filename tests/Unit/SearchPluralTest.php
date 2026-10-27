<?php

namespace Tests\Unit;

use Tests\TestCase;

class SearchPluralTest extends TestCase
{
    public function test_russian_listing_count_forms(): void
    {
        foreach ([1 => 'объявление', 21 => 'объявление', 51 => 'объявление', 2 => 'объявления', 52 => 'объявления', 5 => 'объявлений', 55 => 'объявлений', 11 => 'объявлений', 12 => 'объявлений', 0 => 'объявлений'] as $n => $word) {
            $this->assertSame("Поиск готов: {$n} {$word}", trans_choice('gshome.search.done', $n, ['count' => $n], 'ru'));
        }
    }

    public function test_english_and_georgian_forms(): void
    {
        $this->assertSame('Search is ready: 1 listing', trans_choice('gshome.search.done', 1, ['count' => 1], 'en'));
        $this->assertSame('Search is ready: 5 listings', trans_choice('gshome.search.done', 5, ['count' => 5], 'en'));
        $this->assertSame('ძიება მზადაა: 5 განცხადება', trans_choice('gshome.search.done', 5, ['count' => 5], 'ka'));
    }
}
