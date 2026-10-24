<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\UserResource\Pages\CreateUser;
use App\Filament\SuperAdmin\Resources\UserResource\Pages\EditUser;
use App\Filament\SuperAdmin\Resources\UserResource\Pages\ListUsers;
use App\Models\ImpersonationGrant;
use App\Models\ImpersonationLog;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\EcuadorianId;
use App\Services\WorkspaceEntitlementService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?int $navigationSort = 1;

    /**
     * Platform-panel gate roles this screen can toggle, key => helper label.
     * Distinct from clinic membership roles (WorkspaceMember::ROLES), which are
     * managed per-workspace in the "Equipo" screen, not here.
     */
    private const PLATFORM_ROLES = [
        'platform_admin' => 'Acceso al panel /superadmin (gestión de toda la plataforma).',
        'super_admin' => 'Acceso total al panel /admin (backoffice clínico interno).',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Nueva cuenta')
                ->description('Se crea el perfil y el acceso real. La clave temporal aparece una sola vez al guardar; el correo deberá verificarse. No se otorga acceso al panel de superadmin.')
                ->visibleOn('create')
                ->columns(2)
                ->components([
                    Select::make('creation_mode')
                        ->label('¿A quién vas a agregar?')
                        ->options([
                            'doctor' => 'Médico',
                            'clinic_owner' => 'Dueño de clínica sin perfil médico',
                            'staff' => 'Personal de clínica',
                        ])
                        ->default('doctor')
                        ->required()
                        ->live()
                        ->helperText('Médico: consultorio personal y, si eliges, clínica nueva o membresía. Dueño sin perfil médico: clínica nueva. Personal: membresía en clínica existente.')
                        ->columnSpanFull(),

                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->required()
                        ->email()
                        ->maxLength(255)
                        ->unique(table: 'users', column: 'email'),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30)
                        ->required(fn ($get): bool => $get('creation_mode') === 'doctor'),

                    TextInput::make('id_number')
                        ->label('Cédula ecuatoriana')
                        ->rule(EcuadorianId::cedula())
                        ->maxLength(10)
                        ->required(fn ($get): bool => $get('creation_mode') === 'doctor')
                        ->visible(fn ($get): bool => $get('creation_mode') === 'doctor'),

                    TextInput::make('city')
                        ->label('Ciudad')
                        ->maxLength(120)
                        ->required(fn ($get): bool => $get('creation_mode') === 'doctor')
                        ->visible(fn ($get): bool => $get('creation_mode') === 'doctor'),

                    Select::make('specialty_id')
                        ->label('Especialidad principal')
                        ->options(fn (): array => Specialty::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(fn ($get): bool => $get('creation_mode') === 'doctor')
                        ->visible(fn ($get): bool => $get('creation_mode') === 'doctor'),

                    Select::make('clinic_assignment')
                        ->label('Clínica del médico')
                        ->options([
                            'none' => 'Solo consultorio personal',
                            'new' => 'Crear una clínica nueva y conservar su consultorio',
                            'existing' => 'Incorporar a una clínica existente',
                        ])
                        ->default('none')
                        ->required()
                        ->live()
                        ->visible(fn ($get): bool => $get('creation_mode') === 'doctor')
                        ->columnSpanFull(),

                    TextInput::make('clinic_name')
                        ->label('Nombre de la clínica nueva')
                        ->maxLength(255)
                        ->required(fn ($get): bool => $get('creation_mode') === 'clinic_owner'
                            || ($get('creation_mode') === 'doctor' && $get('clinic_assignment') === 'new'))
                        ->visible(fn ($get): bool => $get('creation_mode') === 'clinic_owner'
                            || ($get('creation_mode') === 'doctor' && $get('clinic_assignment') === 'new')),

                    Select::make('clinic_id')
                        ->label('Clínica existente')
                        ->options(fn (): array => Workspace::query()->where('type', 'clinic')->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(fn ($get): bool => $get('creation_mode') === 'staff'
                            || ($get('creation_mode') === 'doctor' && $get('clinic_assignment') === 'existing'))
                        ->visible(fn ($get): bool => $get('creation_mode') === 'staff'
                            || ($get('creation_mode') === 'doctor' && $get('clinic_assignment') === 'existing')),

                    Select::make('role')
                        ->label('Función en la clínica')
                        ->options([
                            'nurse' => 'Enfermería',
                            'receptionist' => 'Recepción',
                            'billing' => 'Caja',
                            'admin' => 'Administración',
                        ])
                        ->required(fn ($get): bool => $get('creation_mode') === 'staff')
                        ->visible(fn ($get): bool => $get('creation_mode') === 'staff'),
                ]),

            Section::make('Datos de la cuenta')
                ->visibleOn('edit')
                ->columns(2)
                ->components([
                    TextInput::make('name')->label('Nombre completo')->required()->maxLength(255),
                    TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255)
                        ->unique(table: 'users', column: 'email', ignoreRecord: true),
                    Select::make('account_type')->label('Tipo de cuenta')->options([
                        'doctor' => 'Médico', 'staff' => 'Personal',
                    ])->disabled()->dehydrated(false),
                ]),

            Section::make('Acceso a paneles internos')
                ->visibleOn('edit')
                ->description('Permisos de plataforma; son distintos de la función de cada persona en su clínica.')
                ->columns(2)
                ->components(
                    collect(self::PLATFORM_ROLES)
                        ->map(fn (string $help, string $role) => Toggle::make($role)
                            ->label(Str::headline($role))
                            ->helperText($help)
                            ->dehydrated(false)
                            ->afterStateHydrated(
                                fn ($component, ?User $record) => $component->state($record?->hasRole($role) ?? false)
                            ))
                        ->values()
                        ->all()
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'doctor' ? 'Médico' : 'Personal')
                    ->color(fn (string $state) => match ($state) {
                        'doctor' => 'info',
                        'staff' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('suspended')
                    ->label('Habilitado')
                    ->getStateUsing(fn (User $record): bool => ! $record->isSuspended())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-no-symbol')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('memberships_count')
                    ->label('Espacios')
                    ->counts('memberships')
                    ->sortable(),

                TextColumn::make('clinic_creation')
                    ->label('Puede crear clínica')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => self::clinicCreationState($record))
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'Sí') => 'success',
                        $state === 'Vencido' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('roles')
                    ->label('Roles')
                    ->formatStateUsing(fn (User $record) => $record->roles->pluck('name')->join(', ') ?: '—')
                    ->getStateUsing(fn (User $record) => $record->roles->pluck('name')->join(', ') ?: '—'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('account_type')
                    ->label('Tipo de cuenta')
                    ->options([
                        'doctor' => 'Médico',
                        'staff' => 'Personal',
                    ]),

                TernaryFilter::make('suspended_at')
                    ->label('Suspensión')
                    ->placeholder('Todos')
                    ->trueLabel('Suspendido')
                    ->falseLabel('Habilitado')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('suspended_at'),
                        false: fn ($query) => $query->whereNull('suspended_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::allowClinicAction(),

                ActionGroup::make([
                    Action::make('suspend')
                        ->label('Suspender')
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->visible(fn (User $record): bool => ! $record->isSuspended() && $record->id !== auth()->id())
                        ->requiresConfirmation()
                        ->modalDescription('El usuario perderá acceso inmediato a ambos paneles y a la app (login/token bloqueados).')
                        ->form([
                            TextInput::make('suspended_reason')
                                ->label('Motivo (opcional)')
                                ->maxLength(255),
                        ])
                        ->action(function (array $data, User $record): void {
                            $record->update([
                                'suspended_at' => now(),
                                'suspended_reason' => $data['suspended_reason'] ?? null,
                            ]);

                            Notification::make()->title('Usuario suspendido.')->warning()->send();
                        }),

                    Action::make('reactivate')
                        ->label('Reactivar')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (User $record): bool => $record->isSuspended())
                        ->requiresConfirmation()
                        ->action(function (User $record): void {
                            $record->update(['suspended_at' => null, 'suspended_reason' => null]);

                            Notification::make()->title('Usuario reactivado.')->success()->send();
                        }),

                    Action::make('resetPassword')
                        ->label('Generar clave temporal')
                        ->icon('heroicon-m-key')
                        ->color('gray')
                        ->visible(fn (User $record): bool => ! $record->hasAnyRole(['platform_admin', 'super_admin']))
                        ->requiresConfirmation()
                        ->modalDescription('Se revocarán los tokens de la app. La nueva clave se mostrará una sola vez para entregarla por un canal seguro.')
                        ->action(function (User $record): void {
                            if ($record->hasAnyRole(['platform_admin', 'super_admin'])) {
                                Notification::make()->title('Usa la recuperación normal para cuentas de panel interno.')->danger()->send();

                                return;
                            }

                            $password = Str::password(24);
                            DB::transaction(function () use ($record, $password): void {
                                $record->forceFill([
                                    'password' => Hash::make($password),
                                    'must_change_password' => true,
                                    'remember_token' => Str::random(60),
                                ])->save();
                                $record->tokens()->delete();
                            });

                            Notification::make()
                                ->title('Clave temporal generada. Cópiala ahora.')
                                ->body("{$record->email} · Clave temporal: {$password}. Solo se muestra esta vez. Entrégala por un canal seguro.")
                                ->success()
                                ->persistent()
                                ->send();
                        }),

                    Action::make('impersonate')
                        ->label('Entrar temporalmente como usuario')
                        ->icon('heroicon-m-identification')
                        ->color('warning')
                        ->visible(fn (User $record): bool => ! $record->isSuspended()
                            && ! $record->hasRole('platform_admin')
                            && ($record->doctor !== null || $record->memberships()->where('status', 'active')->exists()))
                        ->requiresConfirmation()
                        ->modalDescription('Se generará un enlace de acceso temporal (válido 5 min, un solo uso) a nombre de este usuario. Queda registrado en el log de auditoría.')
                        ->action(function (User $record): void {
                            // A one-time exchange code — not a live bearer token — travels
                            // in the URL. The real Sanctum token is only minted at redemption
                            // (Api\AuthController::exchangeImpersonation), so a link caught in
                            // browser history or an access log is worthless after first use.
                            $code = bin2hex(random_bytes(32));

                            ImpersonationGrant::create([
                                'code' => $code,
                                'target_user_id' => $record->id,
                                'actor_user_id' => auth()->id(),
                                'expires_at' => now()->addMinutes(5),
                            ]);

                            ImpersonationLog::create([
                                'actor_user_id' => auth()->id(),
                                'target_user_id' => $record->id,
                                'ip_address' => request()?->ip(),
                                'created_at' => now(),
                            ]);

                            $link = rtrim(config('app.frontend_url'), '/').'/impersonate?code='.$code;

                            Notification::make()
                                ->title('Enlace de acceso temporal generado')
                                ->body($link)
                                ->success()
                                ->persistent()
                                ->send();
                        }),
                ])
                    ->label('Más acciones')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    /**
     * Whether this account can create a clinic today, in words for the table.
     * Mirrors ClinicService::claimableClinicGrant, so the column says exactly
     * what the app will answer when they try.
     */
    public static function clinicCreationState(User $user): string
    {
        if ($user->hasAnyRole(['platform_admin', 'super_admin'])) {
            return 'No aplica';
        }

        $grant = $user->accountTrialGrant;

        if (! $grant || ! $grant->allowsFirstClinicEntitlement()) {
            return 'Sin permiso';
        }

        if ($grant->first_clinic_claimed_at !== null) {
            return 'Ya creó su clínica';
        }

        if (! $grant->ends_at->greaterThan(now('America/Guayaquil'))) {
            return 'Vencido';
        }

        return 'Sí, hasta '.$grant->ends_at->setTimezone('America/Guayaquil')->format('d/m/Y');
    }

    /**
     * «Permitir crear clínicas»: hands the account the right to create one more
     * clinic of its own. Used from the users table and from the edit page.
     */
    public static function allowClinicAction(): Action
    {
        return Action::make('allow_clinic')
            ->label('Permitir crear clínicas')
            ->icon('heroicon-m-building-office-2')
            ->color('primary')
            ->visible(fn (User $record): bool => ! $record->hasAnyRole(['platform_admin', 'super_admin'])
                && ($record->isDoctor() || $record->isStaff()))
            ->modalHeading(fn (User $record): string => "Permitir que {$record->name} cree una clínica")
            ->modalDescription('Le devuelve a esta persona el derecho a crear una clínica propia. Su consultorio y sus clínicas actuales no cambian. La clínica nueva empieza con prueba hasta la fecha que elijas; luego le cambias el plan desde Clínicas.')
            ->schema([
                DatePicker::make('ends_at')
                    ->label('Puede crearla hasta el')
                    ->required()
                    ->native(false)
                    ->minDate(now())
                    ->default(now()->addDays(30))
                    ->helperText('Si ya tenía una fecha más lejana, se conserva esa.'),
                Textarea::make('operator_note')
                    ->label('Nota (opcional)')
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (User $record, array $data): void {
                try {
                    $grant = app(WorkspaceEntitlementService::class)->allowClinicCreation(
                        $record,
                        CarbonImmutable::parse($data['ends_at'], 'America/Guayaquil')->endOfDay(),
                        auth()->user(),
                        $data['operator_note'] ?? null,
                    );
                } catch (LogicException $e) {
                    Notification::make()->title('No se pudo habilitar')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Listo: ya puede crear una clínica.')
                    ->body("{$record->name} puede crearla hasta el ".$grant->ends_at->setTimezone('America/Guayaquil')->format('d/m/Y').'.')
                    ->success()
                    ->send();
            });
    }

    /** Eager-loads the grant the "Puede crear clínica" column reads. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('accountTrialGrant');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Applies the "Platform roles" toggles from a create/edit form's raw data
     * array. assignRole/removeRole (not syncRoles) so clinic-facing Shield
     * roles the user might separately hold are left untouched.
     *
     * @param  array<string, mixed>  $data
     */
    public static function syncPlatformRoles(User $user, array $data): void
    {
        foreach (array_keys(self::PLATFORM_ROLES) as $role) {
            // firstOrCreate: removeRole() throws RoleDoesNotExist for a role
            // that has never been created, and a fresh install may not have
            // seeded every platform role yet.
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

            if ($data[$role] ?? false) {
                $user->assignRole($role);
            } else {
                $user->removeRole($role);
            }
        }
    }
}
