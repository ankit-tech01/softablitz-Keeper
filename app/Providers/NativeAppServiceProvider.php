<?php

namespace App\Providers;

use Native\Desktop\ChildProcess;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {

        Window::open()
            ->width(1200)
            ->height(800);

        if (! config('nativephp-internal.running')) {
            return;
        }

        MenuBar::create()
            ->icon(public_path('icon.ico'))
            ->label('Keeper')
            ->tooltip('Sanctum Keeper')
            ->onlyShowContextMenu()
            ->showDockIcon(PHP_OS_FAMILY !== 'Darwin')
            ->withContextMenu(Menu::make(
                Menu::link(route('home'), 'Open Keeper'),
                Menu::link(route('home', ['tab' => 'activity']), 'Recent activity'),
                Menu::separator(),
                Menu::quit('Quit Keeper'),
            ));

        app(ChildProcess::class)->artisan(
            ['keeper:watch'],
            'keeper_watcher',
            persistent: true,
            iniSettings: ['memory_limit' => '128M'],
        );
    }

    /**
     * Return an array of php.ini directives to be set.
     *
     * @return array<string, mixed>
     */
    public function phpIni(): array
    {
        return [];
    }
}
