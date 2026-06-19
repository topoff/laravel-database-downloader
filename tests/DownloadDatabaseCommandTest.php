<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand;
use Topoff\DatabaseDownloader\Events\DatabaseImported;

it('can run the db:download command help', function (): void {
    $this->artisan('db:download --help')
        ->assertExitCode(0);
});

it('dispatches DatabaseImported event', function (): void {
    Event::fake();

    DatabaseImported::dispatch(true);

    Event::assertDispatched(DatabaseImported::class, fn ($event): bool => $event->filesImported === true);
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
    $command = app()->make(DownloadDatabaseCommand::class);
    $signature = $command->getNativeDefinition();

    expect($signature->hasOption('source'))->toBeTrue()
        ->and($signature->hasOption('dropExisting'))->toBeTrue()
        ->and($signature->hasOption('files'))->toBeTrue()
        ->and($signature->hasOption('dbName'))->toBeTrue()
        ->and($signature->hasOption('import-from-local-file-path'))->toBeTrue()
        ->and($signature->hasOption('table'))->toBeTrue();
});

it('validates table name rejects invalid characters', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'validateTableName');

    expect(fn (): mixed => $method->invoke($command, 'users; DROP TABLE'))
        ->toThrow(RuntimeException::class, 'Invalid table name');
});

it('validates table name accepts valid names', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'validateTableName');

    expect($method->invoke($command, 'users'))->toBe('users')
        ->and($method->invoke($command, 'user_profiles'))->toBe('user_profiles')
        ->and($method->invoke($command, 'my-table'))->toBe('my-table');
});

it('builds remote dump command without column-statistics when remote is MariaDB', function (): void {
    $command = new DownloadDatabaseCommand;

    $dbName = new ReflectionProperty($command, 'dbName');
    $dbName->setValue($command, 'mydb');

    $method = new ReflectionMethod($command, 'buildRemoteDumpCommand');
    $config = [
        'host' => 'remote.example.com',
        'ssh_user' => 'deploy',
        'mysql_config_path' => '~/mysql-login.cnf',
    ];

    $sql = $method->invoke($command, $config, '', '/tmp/mydb.sql', 'mariadb-dump', '');

    expect($sql)->not->toContain('--column-statistics')
        ->and($sql)->toContain('mariadb-dump --defaults-extra-file=')
        ->and($sql)->toContain("ssh 'deploy'@'remote.example.com'")
        ->and($sql)->toContain('--databases')
        ->and($sql)->toContain("> '/tmp/mydb.sql'");
});

it('builds remote dump command with column-statistics when remote is MySQL 8+', function (): void {
    $command = new DownloadDatabaseCommand;

    $dbName = new ReflectionProperty($command, 'dbName');
    $dbName->setValue($command, 'mydb');

    $method = new ReflectionMethod($command, 'buildRemoteDumpCommand');
    $config = [
        'host' => 'remote.example.com',
        'ssh_user' => 'deploy',
        'mysql_config_path' => '~/mysql-login.cnf',
    ];

    $sql = $method->invoke($command, $config, '', '/tmp/mydb.sql', 'mysqldump', ' --column-statistics=0');

    expect($sql)->toContain('mysqldump --defaults-extra-file=')
        ->and($sql)->toContain('--column-statistics=0')
        ->and($sql)->toContain('--databases');
});

it('respects structure-only and table-scoped variants when emitting flags', function (): void {
    $command = new DownloadDatabaseCommand;

    $dbName = new ReflectionProperty($command, 'dbName');
    $dbName->setValue($command, 'mydb');

    $table = new ReflectionProperty($command, 'table');
    $table->setValue($command, 'users');

    $method = new ReflectionMethod($command, 'buildRemoteDumpCommand');
    $config = [
        'host' => 'remote.example.com',
        'ssh_user' => 'deploy',
        'mysql_config_path' => '~/mysql-login.cnf',
    ];

    $sql = $method->invoke($command, $config, ' --no-data', '/tmp/mydb.sql', 'mariadb-dump', '');

    expect($sql)->toContain("'mydb' 'users' --no-data")
        ->and($sql)->toContain('mariadb-dump')
        ->and($sql)->not->toContain('--column-statistics')
        ->and($sql)->not->toContain('--databases');
});

it('caches remote dump config probe per host', function (): void {
    $command = new DownloadDatabaseCommand;

    $cache = new ReflectionProperty($command, 'remoteDumpConfigCache');
    $cache->setValue($command, [
        'deploy@remote.example.com' => ['binary' => 'mariadb-dump', 'flags' => ''],
    ]);

    $method = new ReflectionMethod($command, 'detectRemoteDumpConfig');
    $config = [
        'host' => 'remote.example.com',
        'ssh_user' => 'deploy',
        'mysql_config_path' => '~/mysql-login.cnf',
    ];

    expect($method->invoke($command, $config))->toBe(['binary' => 'mariadb-dump', 'flags' => '']);
});

it('parses probe output for MariaDB (prefers mariadb-dump, no flags)', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'parseRemoteDumpProbe');

    expect($method->invoke($command, ['BIN=mariadb-dump', 'COL=no']))
        ->toBe(['binary' => 'mariadb-dump', 'flags' => ' --no-tablespaces']);
});

it('parses probe output for MySQL 8 (mysqldump with column-statistics)', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'parseRemoteDumpProbe');

    expect($method->invoke($command, ['BIN=mysqldump', 'COL=yes']))
        ->toBe(['binary' => 'mysqldump', 'flags' => ' --no-tablespaces --column-statistics=0']);
});

it('parses probe output for pre-8 MySQL (mysqldump, no column-statistics)', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'parseRemoteDumpProbe');

    expect($method->invoke($command, ['BIN=mysqldump', 'COL=no']))
        ->toBe(['binary' => 'mysqldump', 'flags' => ' --no-tablespaces']);
});

it('falls back to safe defaults when probe output is empty or unrecognised', function (): void {
    $command = new DownloadDatabaseCommand;
    $method = new ReflectionMethod($command, 'parseRemoteDumpProbe');

    expect($method->invoke($command, []))
        ->toBe(['binary' => 'mysqldump', 'flags' => ' --no-tablespaces']);

    expect($method->invoke($command, ['junk', 'BIN=evil; rm -rf /', 'COL=maybe']))
        ->toBe(['binary' => 'mysqldump', 'flags' => ' --no-tablespaces']);
});
