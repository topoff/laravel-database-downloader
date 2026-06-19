<?php

use Illuminate\Support\Facades\App;
use Topoff\DatabaseDownloader\Commands\CreateDatabaseCommand;

it('can run the db:create command help', function (): void {
    $this->artisan('db:create --help')
        ->assertExitCode(0);
});

it('prevents execution in production environment', function (): void {
    App::shouldReceive('environment')->with('production')->andReturn(true);
    App::shouldReceive('environment')->andReturn('production');

    $this->artisan('db:create')
        ->expectsOutputToContain('This command cannot be executed in production environment')
        ->assertExitCode(1);
});

it('has the correct signature and options', function (): void {
    $command = app()->make(CreateDatabaseCommand::class);
    $signature = $command->getNativeDefinition();

    expect($signature->hasOption('dbName'))->toBeTrue()
        ->and($signature->hasOption('charset'))->toBeTrue()
        ->and($signature->hasOption('collation'))->toBeTrue()
        ->and($signature->hasOption('dropExisting'))->toBeTrue();
});

it('validates db name rejects invalid characters', function (): void {
    $command = new CreateDatabaseCommand;
    $method = new ReflectionMethod($command, 'validateDbName');

    expect(fn (): mixed => $method->invoke($command, 'db; DROP DATABASE'))
        ->toThrow(RuntimeException::class, 'Invalid database name');
});

it('validates db name accepts valid names', function (): void {
    $command = new CreateDatabaseCommand;
    $method = new ReflectionMethod($command, 'validateDbName');

    expect($method->invoke($command, 'my_db'))->toBe('my_db')
        ->and($method->invoke($command, 'my-db'))->toBe('my-db');
});

it('rejects invalid charset or collation identifiers', function (): void {
    $command = new CreateDatabaseCommand;
    $method = new ReflectionMethod($command, 'escapeMysqlIdentifier');

    expect(fn (): mixed => $method->invoke($command, 'utf8mb4; DROP'))
        ->toThrow(RuntimeException::class, 'Invalid MySQL identifier')
        ->and($method->invoke($command, 'utf8mb4_unicode_ci'))->toBe('utf8mb4_unicode_ci');
});
