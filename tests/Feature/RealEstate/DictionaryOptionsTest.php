<?php

namespace Tests\Feature\RealEstate;

use App\RealEstate\DictionaryOptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DictionaryOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        RealEstateFake::fake();
    }

    public function test_any_of_three_languages_finds_the_same_city_and_label_follows_locale(): void
    {
        app()->setLocale('ka');
        foreach (['Тбилиси', 'tbilisi', ' TBILISI ', 'თბილისი', 'билис'] as $term) {
            $this->assertSame([1 => 'თბილისი'], DictionaryOptions::search('cities', [], $term), $term);
        }
        $this->assertSame([], DictionaryOptions::search('cities', [], 'Батуми'));

        app()->setLocale('ru');
        $this->assertSame([1 => 'Тбилиси'], DictionaryOptions::search('cities', [], 'Tbilisi'));
        $this->assertSame([1 => 'Тбилиси'], DictionaryOptions::labels('cities', [], [1, '1']));
    }

    public function test_search_uses_the_cached_dictionary(): void
    {
        app()->setLocale('ka');
        DictionaryOptions::search('cities', [], 'а');
        DictionaryOptions::search('cities', [], 'б');
        DictionaryOptions::options('cities');

        Http::assertSentCount(1);
    }
}
