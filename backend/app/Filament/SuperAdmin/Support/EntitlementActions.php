<?php

namespace App\Filament\SuperAdmin\Support;

use App\Models\SubscriptionPayment;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use LogicException;

/**
 * The operator actions that move a workspace's commercial state.
 *
 * Shared because they are reached from two places — the subscriptions list,
 * where the row is an entitlement, and the workspaces list, where the row is a
 * workspace. Rather than duplicate six audited actions (and risk them drifting
 * apart), each factory takes a resolver that turns whatever the row is into the
 * Workspace they all operate on.
 *
 * Every action requires a note. That note is the only account anyone will have
 * later of why access was granted, extended or taken away.
 *
 * @phpstan-type WorkspaceResolver callable(mixed): Workspace
 */
class EntitlementActions
{
    /**
     * @param  callable(mixed): Workspace  $workspace
     * @return array<int, Action>
     */
    public static function all(callable $workspace): array
    {
        return [
            self::activate($workspace),
            self::recordPayment($workspace),
            self::extendTrial($workspace),
            self::changeClinicPlan($workspace),
            self::setSeatLimit($workspace),
            self::issueTrialGrant($workspace),
            self::setReadOnly($workspace),
            self::restore($workspace),
        ];
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function activate(callable $workspace): Action
    {
        return Action::make('activate_entitlement')
            ->label('Activar acceso')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->schema([self::note()])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                $service = app(WorkspaceEntitlementService::class);
                $entitlement = self::entitlementFor($workspace($record), $data['operator_note']);

                if ($entitlement->state !== WorkspaceEntitlement::STATE_ACTIVE) {
                    $service->activate($entitlement, auth()->user(), null, $data['operator_note']);
                }

                Notification::make()->title('Acceso activado.')->success()->send();
            });
    }

    /**
     * Register money received and, unless told otherwise, push access to the end
     * of the period it covers. The checkbox defaults to on because recording the
     * payment and forgetting the activation is the mistake worth preventing.
     *
     * @param  callable(mixed): Workspace  $workspace
     */
    public static function recordPayment(callable $workspace): Action
    {
        return Action::make('record_payment')
            ->label('Registrar pago')
            ->icon('heroicon-m-banknotes')
            ->color('success')
            ->schema([
                TextInput::make('amount')
                    ->label('Monto (USD)')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(fn (mixed $record) => $workspace($record)->entitlement?->isUnlimited()
                        ? null
                        : $workspace($record)->entitlement?->monthlyPriceUsd())
                    ->helperText('Tarifa de referencia. En el plan ilimitado se acuerda manualmente.'),

                Select::make('method')
                    ->label('Método')
                    ->options([
                        SubscriptionPayment::METHOD_TRANSFER => 'Transferencia',
                        SubscriptionPayment::METHOD_CASH => 'Efectivo',
                        SubscriptionPayment::METHOD_CARD => 'Tarjeta',
                        SubscriptionPayment::METHOD_OTHER => 'Otro',
                    ])
                    ->default(SubscriptionPayment::METHOD_TRANSFER)
                    ->required(),

                DatePicker::make('paid_at')->label('Fecha de pago')->default(today())->required()->native(false),
                DatePicker::make('period_start')->label('Período desde')->default(today())->required()->native(false),
                DatePicker::make('period_end')
                    ->label('Período hasta')
                    ->default(today()->addMonth())
                    ->required()
                    ->native(false)
                    ->after('period_start')
                    ->helperText('Hasta esta fecha se considera al día el pago.'),

                TextInput::make('reference')->label('Referencia')->maxLength(255)
                    ->helperText('Número de transferencia o comprobante, si existe.'),

                self::note()->label('Nota')->required(false),

                Checkbox::make('extend_access')
                    ->label('Extender acceso hasta el fin del período pagado')
                    ->default(true),
            ])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                app(WorkspaceEntitlementService::class)->recordPayment(
                    $workspace($record),
                    [
                        'amount' => $data['amount'],
                        'method' => $data['method'],
                        'paid_at' => CarbonImmutable::parse($data['paid_at']),
                        'period_start' => CarbonImmutable::parse($data['period_start']),
                        'period_end' => CarbonImmutable::parse($data['period_end']),
                        'reference' => $data['reference'] ?? null,
                        'note' => $data['operator_note'] ?? null,
                    ],
                    auth()->user(),
                    extendAccess: (bool) ($data['extend_access'] ?? true),
                );

                Notification::make()->title('Pago registrado.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function extendTrial(callable $workspace): Action
    {
        return Action::make('extend_trial')
            ->label('Extender prueba')
            ->icon('heroicon-m-calendar-days')
            ->color('warning')
            ->modalDescription(fn (mixed $record): string => 'Fin de prueba registrado: '
                .($workspace($record)->entitlement?->trial_ends_at?->format('d/m/Y') ?? 'sin fecha')
                .'. Elige una fecha posterior para extenderla.')
            ->schema([
                DatePicker::make('trial_ends_at')
                    ->label('Nueva fecha de fin')
                    ->required()
                    ->native(false)
                    ->helperText('La prueba termina a las 23:59, hora de Ecuador, del día elegido.'),
                self::note(),
            ])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                app(WorkspaceEntitlementService::class)->extendTrial(
                    self::entitlementFor($workspace($record), $data['operator_note']),
                    CarbonImmutable::parse($data['trial_ends_at'], 'America/Guayaquil')->endOfDay(),
                    auth()->user(),
                    $data['operator_note'],
                );

                Notification::make()->title('Prueba extendida.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function changeClinicPlan(callable $workspace): Action
    {
        return Action::make('change_clinic_plan')
            ->label('Cambiar plan de clínica')
            ->icon('heroicon-m-arrows-right-left')
            ->visible(fn (mixed $record): bool => $workspace($record)->isClinic())
            ->schema([
                Select::make('plan_code')
                    ->label('Nuevo plan')
                    ->options([
                        WorkspaceEntitlement::PLAN_CLINIC => 'Clínica con cupos',
                        WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED => 'Clínica ilimitada · tarifa manual',
                    ])
                    ->default(fn (mixed $record): string => $workspace($record)->entitlement?->plan_code ?? WorkspaceEntitlement::PLAN_CLINIC)
                    ->required()
                    ->helperText('Solo cambia el plan de esta clínica. El consultorio personal del dueño se conserva.'),
                self::note(),
            ])
            ->requiresConfirmation()
            ->action(function (mixed $record, array $data) use ($workspace): void {
                $entitlement = self::entitlementFor($workspace($record), $data['operator_note']);
                if ($entitlement->plan_code === $data['plan_code']) {
                    Notification::make()->title('La clínica ya tiene ese plan. No se registró un cambio.')->warning()->send();

                    return;
                }

                app(WorkspaceEntitlementService::class)->changePlan(
                    $entitlement,
                    $data['plan_code'],
                    auth()->user(),
                    $data['operator_note'],
                );

                Notification::make()->title('Plan de clínica actualizado.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function setSeatLimit(callable $workspace): Action
    {
        return Action::make('set_seat_limit')
            ->label('Definir cupos médicos')
            ->icon('heroicon-m-user-group')
            ->color('gray')
            ->visible(fn (mixed $record): bool => $workspace($record)->isClinic() && ! $workspace($record)->entitlement?->isUnlimited())
            ->schema([
                TextInput::make('clinical_seat_limit')
                    ->label('Médicos que atienden incluidos')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(fn (mixed $record) => $workspace($record)->entitlement?->includedClinicalSeats())
                    ->helperText('El plan clínica incluye 2. Cada médico adicional tiene una tarifa de referencia.'),
                self::note(),
            ])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                app(WorkspaceEntitlementService::class)->setSeatLimit(
                    self::entitlementFor($workspace($record), $data['operator_note']),
                    (int) $data['clinical_seat_limit'],
                    auth()->user(),
                    $data['operator_note'],
                );

                Notification::make()->title('Cupos médicos actualizados.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function issueTrialGrant(callable $workspace): Action
    {
        return Action::make('issue_trial_grant')
            ->label('Otorgar prueba')
            ->icon('heroicon-m-ticket')
            ->color('warning')
            ->schema([
                DatePicker::make('ends_at')
                    ->label('La prueba termina el')
                    ->required()
                    ->native(false)
                    ->helperText('Reabre la prueba de la cuenta, incluida su primera clínica.'),
                self::note(),
            ])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                try {
                    app(WorkspaceEntitlementService::class)->issueAccountTrialGrant(
                        $workspace($record),
                        CarbonImmutable::parse($data['ends_at'], 'America/Guayaquil')->endOfDay(),
                        auth()->user(),
                        $data['operator_note'],
                    );
                } catch (LogicException $e) {
                    Notification::make()
                        ->title('No se pudo otorgar la prueba')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->title('Prueba otorgada.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function setReadOnly(callable $workspace): Action
    {
        return Action::make('set_read_only')
            ->label('Poner en solo lectura')
            ->icon('heroicon-m-lock-closed')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Los registros seguirán disponibles para lectura y descarga. Se detendrán las nuevas consultas, facturas y citas.')
            ->schema([self::note()])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                app(WorkspaceEntitlementService::class)->setReadOnly(
                    self::entitlementFor($workspace($record), $data['operator_note']),
                    auth()->user(),
                    $data['operator_note'],
                );

                Notification::make()->title('Espacio en solo lectura.')->success()->send();
            });
    }

    /** @param callable(mixed): Workspace $workspace */
    public static function restore(callable $workspace): Action
    {
        return Action::make('restore_entitlement')
            ->label('Restaurar acceso')
            ->icon('heroicon-m-arrow-path')
            ->color('primary')
            ->schema([self::note()])
            ->action(function (mixed $record, array $data) use ($workspace): void {
                app(WorkspaceEntitlementService::class)->restore(
                    self::entitlementFor($workspace($record), $data['operator_note']),
                    auth()->user(),
                    null,
                    $data['operator_note'],
                );

                Notification::make()->title('Acceso restaurado.')->success()->send();
            });
    }

    private static function note(): Textarea
    {
        return Textarea::make('operator_note')
            ->label('Motivo de la decisión')
            ->required()
            ->maxLength(500)
            ->helperText('Se guarda en el historial de cambios para auditoría.');
    }

    /**
     * A workspace that predates the rollout has no entitlement until an operator
     * touches it, so every action provisions one on the way in rather than
     * failing on a null.
     */
    private static function entitlementFor(Workspace $workspace, ?string $note): WorkspaceEntitlement
    {
        return $workspace->entitlement
            ?? app(WorkspaceEntitlementService::class)
                ->provisionLegacyEntitlement($workspace, auth()->user(), $note);
    }
}
