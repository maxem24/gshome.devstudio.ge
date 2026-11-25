<?php

namespace Tests\Feature\Crm;

use App\Crm\Pins;
use App\Enums\Direction;
use App\Enums\UnavailableReason;
use App\Models\Company;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ObjectClosedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $lead;

    private User $nino;

    private User $giorgi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $this->giorgi = User::factory()->agent($this->lead)->create();
    }

    private function listing(): Listing
    {
        return Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'cluster' => null,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
    }

    /** @return array<int, string> */
    private function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'])->all();
    }

    public function test_other_holders_notified_with_name_same_company(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $this->giorgi);

        $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->nino);

        $titles = $this->titles($this->giorgi);
        $this->assertCount(1, $titles);
        $this->assertStringContainsString($this->nino->name, $titles[0]);
    }

    public function test_holder_of_company_without_exchange_gets_no_name(): void
    {
        $otherCompany = Company::factory()->create();
        $otherLead = User::factory()->teamLead($otherCompany, Direction::Sale)->create();
        $stranger = User::factory()->agent($otherLead)->create();
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $stranger);

        $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->nino);

        $titles = $this->titles($stranger);
        $this->assertCount(1, $titles);
        $this->assertStringNotContainsString($this->nino->name, $titles[0]);
    }

    public function test_closer_not_notified(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $this->giorgi);

        $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->nino);

        $this->assertSame([], $this->titles($this->nino));
    }

    /** Руководитель закрыл от имени агента: агент, чей пин закрыт, тоже получает уведомление. */
    public function test_pin_owner_notified_when_leader_closes_on_their_behalf(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $this->giorgi);

        $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->lead);

        $this->assertCount(1, $this->titles($this->nino));
        $this->assertCount(1, $this->titles($this->giorgi));
        $this->assertSame([], $this->titles($this->lead));
    }

    public function test_no_notification_when_rolled_back(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $this->giorgi);
        // Fake: запись в БД откатилась бы и сама, проверяем именно отправку.
        Notification::fake();

        try {
            DB::transaction(function () use ($pins, $ninoPin): void {
                $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->nino);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
    }
}
