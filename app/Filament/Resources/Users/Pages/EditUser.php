<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return UserActions::all();
    }

    /** В заголовке — имя человека, а не «Редактирование Человек». */
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof User ? $record->name : parent::getTitle();
    }
}
