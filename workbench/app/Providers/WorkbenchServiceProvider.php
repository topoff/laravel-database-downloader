<?php

namespace Workbench\App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configureLaravelBoostForPackageDevelopment();
    }

    /**
     * Configure Laravel Boost to work with package development.
     * 
     * This fixes the base path and guidelines path for Boost commands
     * so they work correctly when developing packages with Testbench.
     * 
     * @see https://denniskoch.dev/articles/2026-01-26-laravel-boost-for-package-development/
     */
    private function configureLaravelBoostForPackageDevelopment(): void
    {
        if (! class_exists('\\Laravel\\Boost\\BoostServiceProvider')) {
            return;
        }

        Event::listen(CommandStarting::class, function ($event) {
            if (str_starts_with($event->command, 'boost:')) {
                // Set base path to package root (3 levels up from this provider)
                $packageRoot = realpath(__DIR__.'/../../../');
                app()->setBasePath($packageRoot);
                app()->useAppPath(base_path('src'));

                // Configure guidelines path to use package root CLAUDE.md
                config()->set(
                    'boost.code_environments.claude_code.guidelines_path',
                    base_path('CLAUDE.md')
                );
            }
        });
    }
}
