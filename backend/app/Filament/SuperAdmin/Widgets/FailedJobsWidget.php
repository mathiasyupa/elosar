<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Operational visibility over Laravel's `failed_jobs` table (queue worker
 * failures: reminders, SRI authorization, etc.) with a one-click retry.
 */
class FailedJobsWidget extends TableWidget
{
    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        $pending = DB::table('jobs')->count();

        return $table
            ->query(FailedJob::query()->latest('failed_at'))
            ->heading('Estado de las tareas del sistema')
            ->description($pending === 0
                ? 'No hay tareas esperando en cola.'
                : "{$pending} tareas esperando en cola.")
            ->emptyStateHeading('Sin tareas fallidas')
            ->emptyStateDescription('No se registran fallos en recordatorios ni autorizaciones SRI.')
            ->paginated([5, 10, 25])
            ->columns([
                TextColumn::make('queue')
                    ->label('Cola'),

                TextColumn::make('exception')
                    ->label('Error')
                    ->limit(100)
                    ->wrap(),

                TextColumn::make('failed_at')
                    ->label('Falló el')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Reintentar')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->action(function (FailedJob $record): void {
                        Artisan::call('queue:retry', ['id' => [$record->uuid]]);

                        Notification::make()->title('Tarea enviada de nuevo a la cola.')->success()->send();
                    }),

                Action::make('delete')
                    ->label('Eliminar')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record): void {
                        $record->delete();

                        Notification::make()->title('Tarea fallida eliminada.')->success()->send();
                    }),
            ]);
    }
}
