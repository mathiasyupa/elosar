<?php

namespace App\Filament\Resources\Workspaces\Pages;

use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Doctor;
use App\Services\ClinicService;
use App\Support\ActiveWorkspace;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Create clinic.
            Action::make('createClinic')
                ->label('Create clinic')
                ->icon('heroicon-m-building-office-2')
                ->color('primary')
                ->schema([
                    TextInput::make('name')
                        ->label('Clinic name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Pichincha Clinic'),
                ])
                ->action(function (array $data, Action $action) {
                    $doctor = $this->requireDoctor($action);

                    try {
                        $clinic = app(ClinicService::class)->createClinic($doctor, $data['name']);
                    } catch (DomainException) {
                        // The account's included clinic trial is used or
                        // expired. Say so instead of surfacing a 500 — a
                        // platform admin can issue a new grant from the
                        // SuperAdmin panel and retry.
                        Notification::make()
                            ->title('Clinic trial unavailable')
                            ->body('This account already used or no longer has its included clinic trial. Issue a new trial grant from the SuperAdmin panel first.')
                            ->warning()
                            ->persistent()
                            ->send();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title('Clinic created')
                        ->body("Invite code: {$clinic->invite_code}")
                        ->success()
                        ->send();
                }),

            // Join a clinic by invite_code.
            Action::make('joinClinic')
                ->label('Join clinic')
                ->icon('heroicon-m-arrow-right-on-rectangle')
                ->color('gray')
                ->schema([
                    TextInput::make('invite_code')
                        ->label('Invite code')
                        ->required()
                        ->maxLength(12)
                        ->placeholder('e.g. A1B2C3D4'),
                ])
                ->action(function (array $data, Action $action) {
                    $doctor = $this->requireDoctor($action);

                    $clinic = app(ClinicService::class)->joinByInviteCode($doctor, $data['invite_code']);

                    Notification::make()
                        ->title('Joined clinic')
                        ->body("You are now a member of: {$clinic->name}")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Returns the doctor linked to the authenticated user. If the account has no
     * doctor profile attached, shows a clear notice and halts the action
     * (instead of silently hiding the button).
     */
    protected function requireDoctor(Action $action): Doctor
    {
        $doctor = app(ActiveWorkspace::class)->doctor();

        if (! $doctor) {
            Notification::make()
                ->title('Your account is not linked to a doctor profile')
                ->body('To create or join a clinic, your user must be associated with a doctor profile (doctors.user_id).')
                ->warning()
                ->send();

            $action->halt();
        }

        return $doctor;
    }
}
