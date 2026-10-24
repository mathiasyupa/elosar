<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Models\ImpersonationLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Audit visibility for the "Impersonate" action (App\Filament\SuperAdmin\
 * Resources\UserResource) — who logged in as whom, and when.
 */
class RecentImpersonationsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(ImpersonationLog::query()->latest('created_at'))
            ->heading('Accesos temporales recientes')
            ->paginated([5, 10, 25])
            ->columns([
                TextColumn::make('actor.name')
                    ->label('Operador')
                    ->default('—'),

                TextColumn::make('target.name')
                    ->label('Cuenta')
                    ->default('—'),

                TextColumn::make('ip_address')
                    ->label('IP'),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
