<?php

namespace Topoff\DatabaseDownloader;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand;

class DatabaseDownloaderServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-database-downloader')
            ->hasConfigFile()
            ->hasCommand(DownloadDatabaseCommand::class);
    }
}
