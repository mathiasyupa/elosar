<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Support\ActiveWorkspace;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class TodayScheduleWidget extends BaseWidget
{
    protected static ?string $heading = "Today's Schedule";

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        return $table
            ->query(
                Appointment::query()
                    ->when($workspaceId, fn (Builder $q) => $q->where('workspace_id', $workspaceId))
                    ->whereDate('appointment_date', today())
                    ->orderBy('appointment_date', 'asc')
                    ->with(['patient', 'doctor'])
            )
            ->emptyStateHeading('No appointments today')
            ->emptyStateDescription('There are no appointments scheduled for today.')
            ->emptyStateIcon('heroicon-o-calendar')
            ->columns([
                Tables\Columns\TextColumn::make('appointment_date')
                    ->label('Time')
                    ->time('H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('patient.name')
                    ->label('Patient')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('doctor.name')
                    ->label('Doctor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'scheduled'  => 'primary',
                        'completed'  => 'success',
                        'cancelled'  => 'danger',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'scheduled'  => 'Scheduled',
                        'completed'  => 'Completed',
                        'cancelled'  => 'Cancelled',
                        default      => $state,
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Appointment $record): string => AppointmentResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(false),
            ])
            ->paginated(false);
    }
}
