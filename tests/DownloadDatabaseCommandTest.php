<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Topoff\DatabaseDownloader\Events\DatabaseImported;

it('can run the db:download command help', function (): void {
    $this->artisan('db:download --help')
        ->assertExitCode(0);
});

it('dispatches DatabaseImported event', function (): void {
    Event::fake();

    DatabaseImported::dispatch(true);

    Event::assertDispatched(DatabaseImported::class, fn ($event) => $event->filesImported === true);
});

it('prevents execution in production environment', function (): void {
    // We mock App::environment() to return 'production'
    App::shouldReceive('environment')->with('production')->andReturn(true);
    App::shouldReceive('environment')->andReturn('production');

    $this->artisan('db:download')
        ->expectsOutputToContain('This command cannot be executed in production environment')
        ->assertExitCode(1);
});

it('has the correct signature and options', function (): void {
    $command = app()->make(\Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand::class);
    $signature = $command->getNativeDefinition();

    expect($signature->hasOption('dropExisting'))->toBeTrue()
        ->and($signature->hasOption('files'))->toBeTrue()
        ->and($signature->hasOption('dbName'))->toBeTrue()
        ->and($signature->hasOption('import-from-local-file-path'))->toBeTrue();
});
