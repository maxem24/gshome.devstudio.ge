<?php

namespace Tests\Feature\Livewire;

use App\Livewire\NotificationToasts;
use App\Models\User;
use App\Notifications\ListingInfoCollected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationToastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_notifications_do_not_toast_but_new_ones_do(): void
    {
        $user = User::factory()->teamLead()->create();
        $user->notify(new ListingInfoCollected(5, 'Старое', false));
        $this->travel(5)->seconds();
        $this->actingAs($user);

        $component = Livewire::test(NotificationToasts::class);
        $component->call('check')->assertNotDispatched('notificationsSent');

        $this->travel(5)->seconds();
        $user->notify(new ListingInfoCollected(13, 'Новое', false));
        $component->call('check')->assertDispatched('notificationsSent')->assertDispatched('databaseNotificationsSent');

        // Livewire забирает тост из сессии в «claimed» и отдаёт его браузеру событием notificationsSent.
        $toasts = array_values(session('filament.claimed_notifications', []));
        $this->assertCount(1, $toasts);
        $this->assertSame(10000, $toasts[0]['duration']);
        $this->assertStringContainsString('Новое', $toasts[0]['title']);
        $this->assertStringContainsString('/listings/13', $toasts[0]['actions'][0]['url']);
        $this->assertNull($user->notifications()->first()->read_at);

        // повторная проверка ничего не добавляет
        $component->call('check')->assertNotDispatched('notificationsSent');
    }

    public function test_two_notifications_in_the_same_second_both_toast(): void
    {
        $this->freezeTime();
        $user = User::factory()->teamLead()->create();
        $this->actingAs($user);
        $component = Livewire::test(NotificationToasts::class);

        // Та же секунда, что и метка при открытии; проверка — между двумя записями.
        $user->notify(new ListingInfoCollected(13, 'Первое', false));
        $component->call('check')->assertDispatched('notificationsSent');
        $user->notify(new ListingInfoCollected(14, 'Второе', false));
        $component->call('check')->assertDispatched('notificationsSent');

        $titles = array_column(array_values(session('filament.claimed_notifications', [])), 'title');
        $this->assertTrue(collect($titles)->contains(fn (string $t): bool => str_contains($t, 'Второе')));
        $component->call('check')->assertNotDispatched('notificationsSent');
    }
}
