<?php

namespace Topoff\DatabaseDownloader\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared MySQL CLI plumbing: building a secure --defaults-extra-file credential
 * file, creating / dropping the database with the configured charset & collation,
 * and executing shell commands. Used by the download and create commands.
 */
trait InteractsWithMysql
{
    protected string $dbName;

    protected string $dbCharset;

    protected string $dbCollation;

    protected string $mysqlBasicCommand;

    protected ?string $mysqlConfigPath = null;

    protected function canRunInCurrentEnvironment(): bool
    {
        return ! App::environment('production');
    }

    protected function validateDbName(string $dbName): string
    {
        if ($dbName === '' || $dbName === '0') {
            throw new RuntimeException('Database name cannot be empty');
        }

        // Prevent SQL injection in database name
        if (preg_match('/[^a-zA-Z0-9_-]/', $dbName)) {
            throw new RuntimeException('Invalid database name. Only alphanumeric, underscore, and hyphen allowed.');
        }

        return $dbName;
    }

    protected function createMysqlConfigFile(string $dbConnection): void
    {
        $credentials = $this->getDatabaseCredentials($dbConnection);
        $this->validateCredentials($credentials);

        // Use random filename for security
        $this->mysqlConfigPath = database_path('mysql-login-'.Str::random(16).'.cnf');

        // Build config content
        $content = $this->buildMysqlConfigContent($credentials);

        // Create file with restricted permissions
        File::put($this->mysqlConfigPath, $content);
        chmod($this->mysqlConfigPath, 0600); // Only owner can read/write

        $this->mysqlBasicCommand = 'mysql --defaults-extra-file='.escapeshellarg($this->mysqlConfigPath);
    }

    /**
     * @return array{username: ?string, password: ?string, host: ?string, port: ?string}
     */
    protected function getDatabaseCredentials(string $dbConnection): array
    {
        return [
            'username' => config("database.connections.{$dbConnection}.username"),
            'password' => config("database.connections.{$dbConnection}.password"),
            'host' => config("database.connections.{$dbConnection}.host"),
            'port' => config("database.connections.{$dbConnection}.port"),
        ];
    }

    /**
     * @param  array{username: ?string, password: ?string, host: ?string, port: ?string}  $credentials
     */
    protected function validateCredentials(array $credentials): void
    {
        if (empty($credentials['username']) || empty($credentials['host'])) {
            throw new RuntimeException('Database credentials are not configured properly');
        }
    }

    /**
     * @param  array{username: ?string, password: ?string, host: ?string, port: ?string}  $credentials
     */
    protected function buildMysqlConfigContent(array $credentials): string
    {
        return implode("\n", [
            '[client]',
            'user = '.escapeshellarg((string) $credentials['username']),
            'password = '.escapeshellarg($credentials['password'] ?? ''),
            'host = '.escapeshellarg((string) $credentials['host']),
            'port = '.escapeshellarg((string) $credentials['port']),
            '',
        ]);
    }

    protected function removeMysqlConfigFile(): void
    {
        if ($this->mysqlConfigPath && File::exists($this->mysqlConfigPath)) {
            // Securely delete the file
            File::delete($this->mysqlConfigPath);
            $this->mysqlConfigPath = null;
        }
    }

    protected function dropDatabase(): void
    {
        $safeDbName = $this->escapeMysqlIdentifier($this->dbName);
        $command = "{$this->mysqlBasicCommand} --execute=\"DROP DATABASE IF EXISTS {$safeDbName}\"";
        $this->executeShellCommand($command);
    }

    protected function createDatabase(): void
    {
        $safeDbName = $this->escapeMysqlIdentifier($this->dbName);
        $safeCharset = $this->escapeMysqlIdentifier($this->dbCharset);
        $safeCollation = $this->escapeMysqlIdentifier($this->dbCollation);

        $command = "{$this->mysqlBasicCommand} --execute=\"CREATE DATABASE IF NOT EXISTS {$safeDbName} DEFAULT CHARACTER SET {$safeCharset} COLLATE {$safeCollation}\"";
        $this->executeShellCommand($command);
    }

    /**
     * Escape a MySQL identifier (database name, charset, collation) for use in SQL via shell.
     * Only allows alphanumeric characters, underscores, and hyphens.
     */
    protected function escapeMysqlIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            throw new RuntimeException("Invalid MySQL identifier: {$identifier}");
        }

        return $identifier;
    }

    protected function executeShellCommand(string $command): ?string
    {
        if ($this->getOutput()->isVerbose()) {
            $this->components->twoColumnDetail('Command', $command);
        }

        $output = [];
        $resultCode = -1;
        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $this->logCommandFailure($resultCode, $output);
        }

        if ($this->getOutput()->isVerbose() && $output !== []) {
            $this->components->bulletList($output);
        }

        return $output[0] ?? null;
    }

    /**
     * @param  array<int, string>  $output
     */
    protected function logCommandFailure(int $exitCode, array $output): never
    {
        $errorMessage = "Command failed with exit code {$exitCode}";

        if ($output !== []) {
            $errorMessage .= ":\n".implode("\n", $output);
        }

        throw new RuntimeException($errorMessage);
    }

    protected function logAndOutputError(string $error): void
    {
        $this->error($error);
        Log::error($error);
    }

    protected function logAndOutputInfo(string $info): void
    {
        $this->info($info);
        Log::info($info);
    }
}
