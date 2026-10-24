<?php

namespace App\Filament\Resources\Workspaces;

use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\Schemas\WorkspaceForm;
use App\Filament\Resources\Workspaces\Tables\WorkspacesTable;
use App\Models\Workspace;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Filament resource for workspace management.
 */
class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'workspace';

    protected static ?string $pluralModelLabel = 'workspaces';

    public static function form(Schema $schema): Schema
    {
        return WorkspaceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkspacesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Workspace provisioning requires an explicit entitlement audit path.
     * Generic Filament record creation cannot supply that context.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'edit' => EditWorkspace::route('/{record}/edit'),
        ];
    }
}
