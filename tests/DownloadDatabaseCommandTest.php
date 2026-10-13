<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand;
use Topoff\DatabaseDownloader\Events\DatabaseImported;
use Topoff\DatabaseDownloader\Tests\Fixtures\FakeDownloadDatabaseCommand;

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

it('selects all backup dumps sorted so the views-carrying small-backup imports last', function (): void {
    $command = new DownloadDatabaseCommand;
    new ReflectionProperty($command, 'dbName')->setValue($command, 'topofferten');
    new ReflectionProperty($command, 'localPath')->setValue($command, '/tmp/dl/');
    $method = new ReflectionMethod($command, 'selectBackupDumps');

    $files = [
        '/tmp/dl/db-dumps/mysql-small-backup-topofferten.sql.gz',
        '/tmp/dl/db-dumps/mysql-heavy-data-topofferten.sql.gz',
        '/tmp/dl/db-dumps/mysql-heavy-schema-topofferten.sql.gz',
    ];

    expect($method->invoke($command, $files))->toBe([
        '/tmp/dl/db-dumps/mysql-heavy-data-topofferten.sql.gz',
        '/tmp/dl/db-dumps/mysql-heavy-schema-topofferten.sql.gz',
        '/tmp/dl/db-dumps/mysql-small-backup-topofferten.sql.gz',
    ]);
});

it('prefers the legacy single-dump file matching the database name', function (): void {
    $command = new DownloadDatabaseCommand;
    new ReflectionProperty($command, 'dbName')->setValue($command, 'topofferten');
    new ReflectionProperty($command, 'localPath')->setValue($command, '/tmp/dl/');
    $method = new ReflectionMethod($command, 'selectBackupDumps');

    $files = [
        '/tmp/dl/db-dumps/mysql-other.sql.gz',
        '/tmp/dl/db-dumps/mysql-topofferten.sql.gz',
    ];

    expect($method->invoke($command, $files))->toBe(['/tmp/dl/db-dumps/mysql-topofferten.sql.gz']);
});

it('keeps a single backup dump as-is', function (): void {
    $command = new DownloadDatabaseCommand;
    new ReflectionProperty($command, 'dbName')->setValue($command, 'topofferten');
    new ReflectionProperty($command, 'localPath')->setValue($command, '/tmp/dl/');
    $method = new ReflectionMethod($command, 'selectBackupDumps');

    expect($method->invoke($command, ['/tmp/dl/db-dumps/mysql-anything.sql.gz']))
        ->toBe(['/tmp/dl/db-dumps/mysql-anything.sql.gz']);
});

it('throws when the backup archive contains no sql dumps', function (): void {
    $command = new DownloadDatabaseCommand;
    new ReflectionProperty($command, 'dbName')->setValue($command, 'topofferten');
    new ReflectionProperty($command, 'localPath')->setValue($command, '/tmp/dl/');
    $method = new ReflectionMethod($command, 'selectBackupDumps');

    expect(fn (): mixed => $method->invoke($command, []))->toThrow(RuntimeException::class);
});

describe('anonymization', function (): void {
    beforeEach(function (): void {
        FakeDownloadDatabaseCommand::reset();
        $this->app->make(Kernel::class)->registerCommand(new FakeDownloadDatabaseCommand);
        Event::fake();
    });

    afterEach(function (): void {
        File::deleteDirectory(FakeDownloadDatabaseCommand::localPath());
    });

    /** Fake the mysql CLI: one detectable column in the schema listing, UPDATEs succeed or fail. */
    function fakeMysql(bool $updatesFail = false): void
    {
        Process::fake(fn (PendingProcess $process) => str_contains((string) $process->input, 'information_schema')
            ? Process::result("users\temail\tvarchar\t100\t0\nusers\tid\tint\t0\t0\n")
            : Process::result(errorOutput: $updatesFail ? 'ERROR 1062' : '', exitCode: $updatesFail ? 1 : 0));
    }

    $ranUpdate = fn (PendingProcess $process): bool => str_contains((string) $process->input, 'UPDATE `users` SET `email`');

    it('overwrites personal data before the DatabaseImported event', function () use ($ranUpdate): void {
        fakeMysql();

        $this->artisan('db:download-fake')->assertExitCode(0);

        Process::assertRan($ranUpdate);
        Event::assertDispatched(DatabaseImported::class);
        expect(File::exists(FakeDownloadDatabaseCommand::localPath()))->toBeFalse();
    });

    it('skips anonymization with a warning when disabled', function (): void {
        config()->set('database-downloader.anonymize', false);
        Process::fake();

        $this->artisan('db:download-fake --no-interaction')
            ->expectsOutputToContain('personal data is NOT overwritten')
            ->assertExitCode(0);

        Process::assertNothingRan();
        Event::assertDispatched(DatabaseImported::class);
    });

    it('defaults to on, except in staging, when the config key is missing', function (): void {
        config()->set('database-downloader.anonymize');
        $method = new ReflectionMethod(DownloadDatabaseCommand::class, 'shouldAnonymize');

        expect($method->invoke(new DownloadDatabaseCommand))->toBeTrue();

        $this->app->detectEnvironment(fn (): string => 'staging');

        expect($method->invoke(new DownloadDatabaseCommand))->toBeFalse();
    });

    it('fails without dispatching the event when anonymization fails', function (): void {
        fakeMysql(updatesFail: true);

        $this->artisan('db:download-fake')
            ->expectsOutputToContain('may still contain real personal data')
            ->assertExitCode(1);

        Event::assertNotDispatched(DatabaseImported::class);
        expect(File::exists(FakeDownloadDatabaseCommand::localPath()))->toBeFalse();
    });

    it('anonymizes what was imported when the import fails midway', function () use ($ranUpdate): void {
        fakeMysql();
        FakeDownloadDatabaseCommand::$failImport = true;

        $this->artisan('db:download-fake')->assertExitCode(1);

        Process::assertRan($ranUpdate);
        Event::assertNotDispatched(DatabaseImported::class);
        expect(File::exists(FakeDownloadDatabaseCommand::localPath()))->toBeFalse();
    });

    it('aborts before the import when the dump targets another database', function (): void {
        fakeMysql();
        FakeDownloadDatabaseCommand::$dumpContent = "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `production_db`;\nUSE `production_db`;\n";

        $this->artisan('db:download-fake')
            ->expectsOutputToContain("writes into database 'production_db'")
            ->assertExitCode(1);

        expect(FakeDownloadDatabaseCommand::$importCalled)->toBeFalse();
        Process::assertNothingRan();
    });

    it('reads the target database from USE / CREATE DATABASE lines', function (string $line, ?string $expected): void {
        $method = new ReflectionMethod(DownloadDatabaseCommand::class, 'parseTargetDatabase');

        expect($method->invoke(new DownloadDatabaseCommand, $line))->toBe($expected);
    })->with([
        ['USE `production_db`;', 'production_db'],
        ['USE production_db;', 'production_db'],
        ['  use production_db', 'production_db'],
        ['CREATE DATABASE /*!32312 IF NOT EXISTS*/ `production_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;', 'production_db'],
        ['CREATE DATABASE IF NOT EXISTS production_db;', 'production_db'],
        ['create schema `production_db`;', 'production_db'],
        ['USE ;', null],
    ]);

    it('aborts on unquoted, lower-case and late USE statements', function (string $dump): void {
        fakeMysql();
        FakeDownloadDatabaseCommand::$dumpContent = $dump;

        $this->artisan('db:download-fake')->assertExitCode(1);

        expect(FakeDownloadDatabaseCommand::$importCalled)->toBeFalse();
    })->with([
        'unquoted' => ["USE production_db;\n"],
        'lower-case, indented' => ["  use `production_db`;\n"],
        'after many matching ones' => [str_repeat("USE `testdb`;\n", 80)."USE `production_db`;\n"],
        'unreadable name' => ["USE ;\n"],
    ]);

    it('retries the anonymization after a failure and carries on past a failing table', function (): void {
        $updates = 0;
        Process::fake(function (PendingProcess $process) use (&$updates) {
            if (str_contains((string) $process->input, 'information_schema')) {
                return Process::result("leads\temail\tvarchar\t100\t0\nusers\temail\tvarchar\t100\t0\n");
            }

            $updates++;

            // `leads` always fails; `users` must still be anonymized by the retry.
            return str_contains((string) $process->input, 'UPDATE `leads`')
                ? Process::result(errorOutput: 'ERROR 1205 Lock wait timeout', exitCode: 1)
                : Process::result();
        });

        $this->artisan('db:download-fake')
            ->expectsOutputToContain('Not anonymized: leads')
            ->expectsOutputToContain('may still contain real personal data')
            ->assertExitCode(1);

        Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'UPDATE `users`'));
        Event::assertNotDispatched(DatabaseImported::class);
        expect($updates)->toBe(3); // leads (fails, aborts) → retry: leads (fails), users
    });

    it('still anonymizes the other tables when one table cannot be built', function (): void {
        // leads.email is too short for a placeholder email → build() throws for that table
        Process::fake(fn (PendingProcess $process) => str_contains((string) $process->input, 'information_schema')
            ? Process::result("leads\temail\tvarchar\t20\t0\nusers\temail\tvarchar\t100\t0\n")
            : Process::result());

        $this->artisan('db:download-fake')
            ->expectsOutputToContain('Not anonymized: leads')
            ->assertExitCode(1);

        Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'UPDATE `users`'));
        Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains((string) $process->input, 'UPDATE `leads`'));
        Event::assertNotDispatched(DatabaseImported::class);
    });

    it('does not delete an own import file inside the local path without asking', function (): void {
        fakeMysql();
        FakeDownloadDatabaseCommand::$ownImportFile = true;

        $this->artisan('db:download-fake')
            ->expectsConfirmation('Delete downloaded temporary files?', 'no')
            ->assertExitCode(0);

        expect(File::exists(FakeDownloadDatabaseCommand::localPath().'testdb.sql'))->toBeTrue();
    });

    it('accepts a dump that names the target database', function (): void {
        fakeMysql();
        FakeDownloadDatabaseCommand::$dumpContent = "USE `testdb`;\n";

        $this->artisan('db:download-fake')->assertExitCode(0);

        expect(FakeDownloadDatabaseCommand::$importCalled)->toBeTrue();
    });
});
