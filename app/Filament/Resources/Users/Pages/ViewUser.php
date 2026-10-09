<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /** В заголовке — имя человека, а не «Редактирование Человек». */
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof User ? $record->name : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...UserActions::all()];
    }
}
