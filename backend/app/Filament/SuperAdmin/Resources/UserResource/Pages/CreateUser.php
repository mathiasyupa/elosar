<?php

namespace App\Filament\SuperAdmin\Resources\UserResource\Pages;

use App\Filament\SuperAdmin\Resources\UserResource;
use App\Services\SuperAdminAccountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function canCreateAnother(): bool
    {
        return false;
    }

    private ?string $temporaryPassword = null;

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(SuperAdminAccountService::class);
        $common = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ];

        $result = match ($data['creation_mode']) {
            'doctor' => $service->createDoctor(array_merge($common, [
                'id_number' => $data['id_number'],
                'city' => $data['city'],
                'specialty_id' => (int) $data['specialty_id'],
            ], match ($data['clinic_assignment']) {
                'new' => ['clinic_name' => $data['clinic_name']],
                'existing' => ['clinic_id' => (int) $data['clinic_id']],
                default => [],
            })),
            'clinic_owner' => $service->createClinicOwner(array_merge($common, [
                'clinic_name' => $data['clinic_name'],
            ])),
            'staff' => $service->createStaff(array_merge($common, [
                'clinic_id' => (int) $data['clinic_id'],
                'role' => $data['role'],
            ])),
        };

        $this->temporaryPassword = $result['temporary_password'];

        return $result['user'];
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Cuenta creada. Copia la clave temporal ahora.')
            ->body("{$this->record->email} · Clave temporal: {$this->temporaryPassword}. Entrégala por un canal seguro; solo se muestra esta vez. La persona deberá cambiarla al entrar y verificar su correo.")
            ->success()
            ->persistent()
            ->send();

        $this->temporaryPassword = null;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
