<?php

use Illuminate\Support\Facades\Event;
use Topoff\DatabaseDownloader\Events\DatabaseImported;
use Illuminate\Support\Facades\App;

it('can run the db:download command help', function () {
    $this->artisan('db:download --help')
        ->assertExitCode(0);
});

it('dispatches DatabaseImported event', function () {
    Event::fake();

    DatabaseImported::dispatch('test-tenant', true);

    Event::assertDispatched(DatabaseImported::class, function ($event) {
        return $event->tenant === 'test-tenant' && $event->filesImported === true;
    });
});

it('prevents execution in production environment', function () {
    // We mock App::environment() to return 'production'
    App::shouldReceive('environment')->with('production')->andReturn(true);
    App::shouldReceive('environment')->andReturn('production');

    $this->artisan('db:download')
        ->expectsOutputToContain('This command could not be executed, because it run in Env: production')
        ->assertExitCode(0);
});

it('has the correct signature and options', function () {
    $command = app()->make(\Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand::class);
    $signature = $command->getNativeDefinition();

    expect($signature->hasOption('dropExisting'))->toBeTrue()
        ->and($signature->hasOption('source'))->toBeTrue()
        ->and($signature->hasOption('files'))->toBeTrue()
        ->and($signature->hasOption('dbName'))->toBeTrue()
        ->and($signature->hasOption('import-from-local-file-path'))->toBeTrue();
});
