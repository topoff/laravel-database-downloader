<?php

namespace Topoff\DatabaseDownloader\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
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

        // Created with restricted permissions: never readable by others, not even briefly
        $previousUmask = umask(0077);

        try {
            File::put($this->mysqlConfigPath, $content);
        } finally {
            umask($previousUmask);
        }

        chmod($this->mysqlConfigPath, 0600);

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
            'user = '.$this->quoteOptionValue((string) $credentials['username']),
            'password = '.$this->quoteOptionValue($credentials['password'] ?? ''),
            'host = '.$this->quoteOptionValue((string) $credentials['host']),
            'port = '.$this->quoteOptionValue((string) $credentials['port']),
            '',
        ]);
    }

    /**
     * Option-file syntax, not shell syntax: inside double quotes MySQL reads \\ and \"
     * as escapes. A line break cannot be expressed and is rejected.
     */
    protected function quoteOptionValue(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('Database credentials must not contain line breaks');
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
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
        // Via stdin, so the backticks (needed for names like "my-db") never reach a shell.
        $safeDbName = $this->escapeMysqlIdentifier($this->dbName);
        $this->runMysqlWithInput("DROP DATABASE IF EXISTS `{$safeDbName}`;", selectDatabase: false);
    }

    protected function createDatabase(): void
    {
        $safeDbName = $this->escapeMysqlIdentifier($this->dbName);
        $safeCharset = $this->escapeMysqlIdentifier($this->dbCharset);
        $safeCollation = $this->escapeMysqlIdentifier($this->dbCollation);

        $this->runMysqlWithInput("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` DEFAULT CHARACTER SET {$safeCharset} COLLATE {$safeCollation};", selectDatabase: false);
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
            $this->components->bulletList($this->limitVerboseOutput($output));
        }

        return $output[0] ?? null;
    }

    /**
     * Rendering a bullet list costs quadratic time on older libxml2 (Termwind parses it
     * as HTML): unzip of a backup with ~17k files hung for ~47 minutes on libxml2 2.9.14.
     *
     * @param  array<int, string>  $output
     * @return array<int, string>
     */
    protected function limitVerboseOutput(array $output): array
    {
        $maxLines = 20;
        $hiddenLines = count($output) - $maxLines;

        if ($hiddenLines <= 0) {
            return $output;
        }

        return [...array_slice($output, 0, $maxLines), "… {$hiddenLines} more lines not shown"];
    }

    /**
     * Run SQL through the mysql CLI, fed via stdin so the statements never show up
     * in the process list or in a file. Returns the output lines (tab-separated).
     *
     * @return array<int, string>
     */
    protected function runMysqlWithInput(string $sql, bool $selectDatabase = true): array
    {
        $command = $this->mysqlBasicCommand.' --batch --skip-column-names';

        if ($selectDatabase) {
            $command .= ' '.escapeshellarg($this->dbName);
        }

        $result = Process::forever()->input($sql)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('MySQL command failed: '.trim($result->errorOutput()));
        }

        $output = trim($result->output());

        return $output === '' ? [] : explode("\n", $output);
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

    /**
     * A failing logger (full disk, broken channel) must not abort the caller: this
     * also runs in the clean-up of real data after a failed import.
     */
    protected function logAndOutputError(string $error): void
    {
        $this->error($error);
        rescue(fn () => Log::error($error), report: false);
    }

    protected function logAndOutputInfo(string $info): void
    {
        $this->info($info);
        Log::info($info);
    }
}
