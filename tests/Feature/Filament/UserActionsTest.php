<?php

namespace Tests\Feature\Filament;

use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
    }

    public function test_archive_is_hidden_by_default_and_reachable_by_filter(): void
    {
        $lead = User::factory()->teamLead()->create();
        $blocked = User::factory()->teamLead()->blocked()->create();
        $archived = User::factory()->teamLead()->archived()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$lead, $blocked])
            ->assertCanNotSeeTableRecords([$archived])
            ->filterTable('status', [UserStatus::Archived->value])
            ->assertCanSeeTableRecords([$archived])
            ->assertCanNotSeeTableRecords([$lead]);
    }

    public function test_owner_blocks_and_unblocks_from_the_table(): void
    {
        $lead = User::factory()->teamLead()->create();

        Livewire::test(ListUsers::class)->callAction(TestAction::make('block')->table($lead));
        $this->assertSame(UserStatus::Blocked, $lead->refresh()->status);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('unblock')->table($lead));
        $this->assertSame(UserStatus::Active, $lead->refresh()->status);
    }

    public function test_status_actions_are_hidden_for_the_owner_row(): void
    {
        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('block')->table($this->owner))
            ->assertActionHidden(TestAction::make('archive')->table($this->owner));
    }

    public function test_owner_archives_and_restores_from_the_table(): void
    {
        $lead = User::factory()->teamLead()->create();

        Livewire::test(ListUsers::class)->callAction(TestAction::make('archive')->table($lead));
        $this->assertSame(UserStatus::Archived, $lead->refresh()->status);

        Livewire::test(ListUsers::class)
            ->filterTable('status', [UserStatus::Archived->value])
            ->callAction(TestAction::make('restore')->table($lead));
        $this->assertSame(UserStatus::Active, $lead->refresh()->status);
    }

    public function test_change_password_from_the_table(): void
    {
        $lead = User::factory()->teamLead()->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($lead), data: [
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-secret-123', $lead->refresh()->password));
    }

    public function test_change_password_requires_matching_confirmation(): void
    {
        $lead = User::factory()->teamLead()->create();
        $hash = $lead->password;

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($lead), data: [
                'password' => 'new-secret-123',
                'password_confirmation' => 'другое',
            ])
            ->assertHasFormErrors(['password']);

        $this->assertSame($hash, $lead->refresh()->password);
    }

    public function test_edit_page_has_status_and_password_actions_but_no_password_field(): void
    {
        $lead = User::factory()->teamLead()->create();

        Livewire::test(EditUser::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldIsHidden('password')
            ->assertActionVisible('changePassword')
            ->assertActionVisible('block')
            ->assertActionVisible('archive')
            ->callAction('block');

        $this->assertSame(UserStatus::Blocked, $lead->refresh()->status);
    }

    public function test_view_page_has_the_same_actions(): void
    {
        $lead = User::factory()->teamLead()->blocked()->create();

        Livewire::test(ViewUser::class, ['record' => $lead->getRouteKey()])
            ->assertActionVisible('unblock')
            ->assertActionHidden('block');
    }

    public function test_create_requires_password_confirmation(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Нино',
                'email' => 'nino@example.ge',
                'password' => 'secret-pass-1',
                'password_confirmation' => 'другой',
                'role' => 'company_owner',
                'company_id' => User::factory()->companyOwner()->create()->company_id,
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_edit_title_is_the_persons_name(): void
    {
        $lead = User::factory()->teamLead()->create(['name' => 'Нино Беридзе']);

        $page = Livewire::test(EditUser::class, ['record' => $lead->getRouteKey()])->instance();

        $this->assertInstanceOf(EditUser::class, $page);
        $this->assertSame('Нино Беридзе', $page->getTitle());
    }

    /** Сгенерированный пароль скрыт точками — без показа в уведомлении владелец его не узнает. */
    public function test_new_password_is_shown_to_the_owner_after_change(): void
    {
        $lead = User::factory()->teamLead()->create(['email' => 'lead@example.ge']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($lead), data: [
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ]);

        // Так же, как Notification::assertNotified(): читаем то, что увидит владелец.
        $sent = new Notifications;
        $sent->mount();
        $bodies = $sent->notifications->map(fn (Notification $n): string => (string) $n->getBody())->implode(' ');
        $this->assertStringContainsString('new-secret-123', $bodies);
        $this->assertStringContainsString('lead@example.ge', $bodies);
    }

    public function test_view_page_shows_status_and_edit_page_does_not(): void
    {
        $lead = User::factory()->teamLead()->blocked()->create();

        Livewire::test(ViewUser::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldIsVisible('status')
            ->assertFormSet(['status' => UserStatus::Blocked]);

        Livewire::test(EditUser::class, ['record' => $lead->getRouteKey()])
            ->assertFormFieldIsHidden('status');
    }
}
