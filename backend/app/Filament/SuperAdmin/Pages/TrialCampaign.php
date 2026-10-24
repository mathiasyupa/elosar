<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Models\PlatformSetting;
use App\Services\TrialCampaignService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The trial campaign, editable without opening a SQL client.
 *
 * The values live in `platform_settings` and shape every future signup: how
 * many days a new account gets, and the date after which no new trial is
 * issued at all. Running a 45-day campaign, or extending the cut-off before it
 * quietly closes the door on new signups, used to require a database session.
 *
 * Changes apply to grants issued from now on. Trials already running keep the
 * deadline they were given — moving somebody's finish line after the fact is
 * not a setting, it is a decision, and it belongs in the audited per-account
 * actions instead.
 */
class TrialCampaign extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Campaña de prueba';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión comercial';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.superadmin.pages.trial-campaign';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(TrialCampaignService::class)->settings());
    }

    public function getHeading(): string|Htmlable
    {
        return 'Campaña de prueba';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Se aplica a cuentas nuevas. Las pruebas vigentes conservan su fecha de fin; extiéndelas desde Planes y accesos.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('trial_duration_days')
                            ->label('Duración de la prueba (días)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required()
                            ->helperText('La prueba de una cuenta nueva termina a las 23:59, hora de Ecuador, del último día.'),

                        DatePicker::make('trial_enabled_until')
                            ->label('Último día para otorgar pruebas')
                            ->required()
                            ->native(false)
                            ->helperText('Después de esta fecha, las cuentas nuevas comienzan en solo lectura.'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar campaña')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlatformSetting::query()->updateOrCreate(
            ['key' => TrialCampaignService::SETTING_KEY],
            ['value' => [
                'trial_duration_days' => (int) $data['trial_duration_days'],
                'trial_enabled_until' => Carbon::parse($data['trial_enabled_until'])->toDateString(),
            ]],
        );

        try {
            // Read it straight back through the service: it validates the shape
            // and the date, so a value the campaign cannot use is rejected here
            // instead of silently breaking every future signup.
            app(TrialCampaignService::class)->settings();
        } catch (LogicException $e) {
            Notification::make()
                ->title('La campaña no es válida')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Campaña actualizada.')->success()->send();
    }
}
