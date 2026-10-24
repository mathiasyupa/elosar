<?php

namespace App\Filament\SuperAdmin\Resources\WorkspaceResource\RelationManagers;

use App\Models\WorkspaceMember;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only roster of this workspace's members. Team management (add/remove,
 * change roles) stays the clinic owner's own "Equipo" screen — the platform
 * panel only needs visibility for support purposes.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Miembros';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Nombre')
                    ->default('—'),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->default('—'),

                TextColumn::make('roles')
                    ->label('Roles')
                    ->getStateUsing(fn (WorkspaceMember $record) => implode(', ', $record->roleList())),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Activo' : 'Inactivo')
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Ingresó el')
                    ->dateTime('d/m/Y'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
