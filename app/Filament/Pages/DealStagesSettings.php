<?php

namespace App\Filament\Pages;

use App\Crm\DealStages;
use App\Enums\Role;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnitEnum;

/**
 * Этапы сделки: подписи на трёх языках, порядок, скрытие. Только Owner.
 *
 * @property-read Schema $form
 */
class DealStagesSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.deal-stages-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'settings/deal-stages';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::Owner;
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.stages.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.stages.group');
    }

    public function getTitle(): string
    {
        return __('gshome.stages.title');
    }

    public function mount(): void
    {
        $this->form->fill(['stages' => array_map(fn ($s): array => [
            'key' => $s->key,
            'existing' => true,
            'labels' => $s->labels,
            'hidden' => $s->hidden,
            'kind' => $s->kind,
        ], DealStages::all())]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Form::make([
                Repeater::make('stages')
                    ->hiddenLabel()
                    ->reorderable()
                    ->deletable(false)
                    ->addActionLabel(__('gshome.stages.add'))
                    ->itemLabel(fn (array $state): ?string => $state['labels']['ru'] ?? null)
                    ->columns(4)
                    ->schema([
                        Hidden::make('existing')->default(false)->dehydrated(),
                        TextInput::make('key')
                            ->label(__('gshome.stages.key'))
                            ->helperText(__('gshome.stages.key_hint'))
                            ->required()
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->disabled(fn ($get): bool => (bool) $get('existing'))
                            ->dehydrated(),
                        TextInput::make('labels.ru')->label(__('gshome.stages.label_ru'))->required(),
                        TextInput::make('labels.en')->label(__('gshome.stages.label_en')),
                        TextInput::make('labels.ka')->label(__('gshome.stages.label_ka')),
                        Select::make('kind')
                            ->label(__('gshome.stages.kind'))
                            ->options([
                                'normal' => __('gshome.stages.kind_normal'),
                                'won' => __('gshome.stages.kind_won'),
                                'lost' => __('gshome.stages.kind_lost'),
                            ])
                            ->required()
                            ->native(false),
                        Checkbox::make('hidden')->label(__('gshome.stages.hidden'))->inline(false),
                    ]),
            ])->livewireSubmitHandler('save')->footer([
                Action::make('save')->label(__('gshome.stages.save'))->submit('save'),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        try {
            DealStages::save(array_values($state['stages'] ?? []), Auth::user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('gshome.stages.saved'))->send();
    }
}
