<?php

namespace Tests\Feature\Search;

use App\Search\FiltersSummary;
use Tests\TestCase;

class FiltersSummaryBedroomsTest extends TestCase
{
    public function test_bedrooms_are_listed_sorted_with_five_plus(): void
    {
        $this->assertStringContainsString('2, 3, 5+ '.__('gshome.search.bedrooms'), FiltersSummary::make(['bedrooms' => ['5', '2', '3']]));
    }
}
