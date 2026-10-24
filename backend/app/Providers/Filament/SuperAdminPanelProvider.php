<?php

namespace App\Providers\Filament;

use App\Filament\SuperAdmin\Http\Middleware\SetSpanishLocale;
use App\Filament\SuperAdmin\Widgets\CommercialStatsWidget;
use App\Filament\SuperAdmin\Widgets\NeedsAttentionWidget;
use App\Filament\SuperAdmin\Widgets\PlatformStatsWidget;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SuperAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('superadmin')
            ->path('superadmin')
            ->login()
            ->brandName('Elosar · Superadmin')
            ->brandLogo(asset('elosar-mark.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('elosar-mark.png'))
            ->colors([
                'primary' => Color::Violet,
            ])
            ->authGuard('web')
            // This panel sees every clinic on the platform, so a stolen password
            // alone must not be enough. `isRequired` forces enrolment on first
            // login — an account without a second factor cannot get past setup.
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName('Elosar · Superadmin'),
                isRequired: true,
            )
            ->discoverResources(in: app_path('Filament/SuperAdmin/Resources'), for: 'App\Filament\SuperAdmin\Resources')
            ->discoverPages(in: app_path('Filament/SuperAdmin/Pages'), for: 'App\Filament\SuperAdmin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/SuperAdmin/Widgets'), for: 'App\Filament\SuperAdmin\Widgets')
            // Order matters: money first, then what needs a message this week,
            // then who is on the platform. Queue health is last — it belongs on
            // the dashboard, but never above the business.
            ->widgets([
                AccountWidget::class,
                CommercialStatsWidget::class,
                NeedsAttentionWidget::class,
                PlatformStatsWidget::class,
            ])
            ->navigationGroups([
                'Gestión comercial',
                'Plataforma',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([SetSpanishLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
