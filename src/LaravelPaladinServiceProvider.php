<?php

namespace Kekser\LaravelPaladin;

use Kekser\LaravelPaladin\Commands\CleanupCommand;
use Kekser\LaravelPaladin\Commands\HealCommand;
use Kekser\LaravelPaladin\Commands\StatusCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelPaladinServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('paladin')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_healing_attempts_table')
            ->hasCommand(HealCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(CleanupCommand::class);
    }
}
