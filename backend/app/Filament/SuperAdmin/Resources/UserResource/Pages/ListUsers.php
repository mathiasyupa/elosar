<?php

namespace App\Filament\SuperAdmin\Resources\UserResource\Pages;

use App\Filament\SuperAdmin\Resources\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * The create page existed and nothing linked to it.
     *
     * `CreateUser` has been in the resource since it was written, and
     * `UserResource` never disabled `canCreate()` — but a Filament list page only
     * shows the button if it declares one, so the feature was built and
     * unreachable. Found while a platform admin was trying to add an account.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Agregar usuario'),
        ];
    }
}
