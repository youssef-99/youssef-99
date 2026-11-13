<?php

namespace App\Providers\Filament;

use App\Enums\Module;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\SetLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(isSimple: false)
            ->brandName(fn () => __('ERP Web'))
            ->font('Cairo')
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Slate,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->unsavedChangesAlerts()
            ->navigationGroups(array_map(
                fn (Module $m) => NavigationGroup::make(fn () => $m->label())->icon($m->icon())->collapsed($m !== Module::Dashboard),
                array_filter(Module::cases(), fn (Module $m) => $m !== Module::Dashboard),
            ))
            ->userMenuItems([
                Action::make('switch-language')
                    ->label(fn () => app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('locale.switch', app()->getLocale() === 'ar' ? 'en' : 'ar')),
            ])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => Blade::render(
                '<div class="text-center text-sm mt-2">@foreach(config(\'erp.locales\') as $code => $name) @if($code !== app()->getLocale())<a class="text-primary-600 hover:underline mx-2" href="{{ route(\'locale.switch\', $code) }}">{{ $name }}</a>@endif @endforeach</div>'
            ))
            ->renderHook(PanelsRenderHook::FOOTER, fn () => Blade::render(
                '<div class="text-center text-xs text-gray-400 py-3">{{ __(\'ERP Web\') }} · v{{ config(\'erp.version\') }}</div>'
            ))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
