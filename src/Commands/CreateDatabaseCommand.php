<?php

namespace Topoff\DatabaseDownloader\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Topoff\DatabaseDownloader\Concerns\InteractsWithMysql;

class CreateDatabaseCommand extends Command
{
    use InteractsWithMysql;

    protected $signature = 'db:create
    {--dbName= : Use a different database name instead of the configured one}
    {--charset= : Override the charset (defaults to the connection charset)}
    {--collation= : Override the collation (defaults to the connection collation)}
    {--dropExisting : Drop the database first if it already exists}';

    protected $description = 'Create the database with the charset and collation defined in config/database.php';

    public function handle(): int
    {
        if (! $this->canRunInCurrentEnvironment()) {
            $this->logAndOutputError('This command cannot be executed in production environment');

            return self::FAILURE;
        }

        try {
            $this->initializeConfig();

            if ($this->option('dropExisting')) {
                $this->components->task(
                    "Dropping database '{$this->dbName}'",
                    fn () => $this->dropDatabase()
                );
            }

            $this->components->task(
                "Creating database '{$this->dbName}' ({$this->dbCharset} / {$this->dbCollation})",
                fn () => $this->createDatabase()
            );

            $this->logAndOutputInfo('Database created successfully');

            return self::SUCCESS;
        } catch (Throwable $t) {
            $this->logAndOutputError('Error: '.$t->getMessage());
            Log::error('Database creation failed', [
                'exception' => $t,
                'trace' => $t->getTraceAsString(),
            ]);

            return self::FAILURE;
        } finally {
            $this->removeMysqlConfigFile();
        }
    }

    protected function initializeConfig(): void
    {
        $dbConnection = config('database-downloader.mysql_connection', 'mysql');

        $this->dbName = $this->validateDbName(
            $this->option('dbName') ?? config("database.connections.{$dbConnection}.database")
        );
        $this->dbCharset = $this->option('charset')
            ?? config("database.connections.{$dbConnection}.charset", 'utf8mb4');
        $this->dbCollation = $this->option('collation')
            ?? config("database.connections.{$dbConnection}.collation", 'utf8mb4_unicode_ci');

        $this->createMysqlConfigFile($dbConnection);
    }
}
