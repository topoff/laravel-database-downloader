<?php

namespace Topoff\DatabaseDownloader\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Topoff\DatabaseDownloader\Events\DatabaseImported;

class DownloadDatabaseCommand extends Command
{
    protected $signature = 'db:download
    {--dropExisting : Drop the database before import if it exists} 
    {--source=backup : Data source: backup|live-dump|live-dump-structure|staging-dump|staging-dump-structure} 
    {--files : Download and import files from public/storage} 
    {--dbName= : Use a different database name}
    {--import-from-local-file-path= : Import from a specific local file instead of downloading}';

    protected $description = 'Import the live database from the live or backup server to local';

    private string $tenant;

    private string $localPath;

    private string $dbName;

    private string $dbCharset;

    private string $dbCollation;

    private string $mysqlBasicCommand;

    private string $backupPath;

    private ?string $mysqlConfigPath = null;

    private const ALLOWED_SOURCES = [
        'backup',
        'live-dump',
        'live-dump-structure',
        'staging-dump',
        'staging-dump-structure',
    ];

    public function handle(): int
    {
        $this->logStart();

        if (! $this->canRunInCurrentEnvironment()) {
            $this->logAndOutputError('This command cannot be executed in production environment');

            return self::FAILURE;
        }

        if (! $this->validateSource()) {
            $this->logAndOutputError('Invalid source. Allowed: '.implode(', ', self::ALLOWED_SOURCES));

            return self::FAILURE;
        }

        try {
            $this->prepare();

            $fileToImport = $this->getFileToImport();

            if (empty($fileToImport) || ! File::exists($fileToImport)) {
                $this->logAndOutputInfo('No file to import found');

                return self::FAILURE;
            }

            $this->importDatabase($fileToImport);
            $this->dispatchEvents();
            $this->cleanup();

            $this->logAndOutputInfo('Database import completed successfully');
            Log::debug('Finished '.static::class);

            return self::SUCCESS;
        } catch (Throwable $t) {
            $this->logAndOutputError('Error: '.$t->getMessage());
            Log::error('Database import failed', [
                'exception' => $t,
                'trace' => $t->getTraceAsString(),
            ]);

            return self::FAILURE;
        } finally {
            $this->removeMysqlConfigFile();
        }
    }

    private function logStart(): void
    {
        $this->logAndOutputDebug(sprintf(
            'Starting database download - Source: %s, Drop existing: %s, Files: %s',
            $this->option('source'),
            $this->option('dropExisting') ? 'yes' : 'no',
            $this->option('files') ? 'yes' : 'no'
        ));
    }

    private function canRunInCurrentEnvironment(): bool
    {
        return ! App::environment('production');
    }

    private function validateSource(): bool
    {
        return in_array($this->option('source'), self::ALLOWED_SOURCES, true);
    }

    private function prepare(): void
    {
        $this->initializeConfig();
        $this->call('cache:clear');
        $this->ensureLocalDirectoryExists();
    }

    private function ensureLocalDirectoryExists(): void
    {
        if (File::exists($this->localPath)) {
            File::deleteDirectory($this->localPath);
        }

        File::makeDirectory($this->localPath, 0755, true, true);
    }

    private function initializeConfig(): void
    {
        $dbConnection = config('database-downloader.mysql_connection', 'mysql');

        $this->tenant = $this->validateTenant(config('app.tenant', 'default'));
        $this->localPath = $this->validateLocalPath(config('database-downloader.local_path'));
        $this->dbName = $this->validateDbName(
            $this->option('dbName') ?? config("database.connections.{$dbConnection}.database")
        );
        $this->dbCharset = config("database.connections.{$dbConnection}.charset", 'utf8mb4');
        $this->dbCollation = config("database.connections.{$dbConnection}.collation", 'utf8mb4_unicode_ci');

        $this->backupPath = $this->buildBackupPath();
        $this->createMysqlConfigFile($dbConnection);
    }

    private function validateTenant(string $tenant): string
    {
        if (empty($tenant) || preg_match('/[^a-zA-Z0-9_-]/', $tenant)) {
            throw new RuntimeException('Invalid tenant name. Only alphanumeric, underscore, and hyphen allowed.');
        }

        return $tenant;
    }

    private function validateLocalPath(string $path): string
    {
        if (empty($path)) {
            throw new RuntimeException('Local path cannot be empty');
        }

        // Ensure path is within database directory for security
        $realPath = realpath(dirname($path)) ?: dirname($path);
        $databasePath = realpath(database_path()) ?: database_path();

        if (! Str::startsWith($realPath, $databasePath)) {
            throw new RuntimeException('Local path must be within the database directory');
        }

        return $path;
    }

    private function validateDbName(string $dbName): string
    {
        if (empty($dbName)) {
            throw new RuntimeException('Database name cannot be empty');
        }

        // Prevent SQL injection in database name
        if (preg_match('/[^a-zA-Z0-9_-]/', $dbName)) {
            throw new RuntimeException('Invalid database name. Only alphanumeric, underscore, and hyphen allowed.');
        }

        return $dbName;
    }

    private function buildBackupPath(): string
    {
        return str_replace(
            ['{tenant}', '{backup_name}'],
            [$this->tenant, config('backup.backup.name', 'default')],
            config('database-downloader.backup_path_template')
        );
    }

    private function createMysqlConfigFile(string $dbConnection): void
    {
        $username = config("database.connections.{$dbConnection}.username");
        $password = config("database.connections.{$dbConnection}.password");
        $host = config("database.connections.{$dbConnection}.host");
        $port = config("database.connections.{$dbConnection}.port");

        if (empty($username) || empty($host)) {
            throw new RuntimeException('Database credentials are not configured properly');
        }

        // Use random filename for security
        $this->mysqlConfigPath = database_path('mysql-login-'.Str::random(16).'.cnf');

        // Build config with proper escaping
        $content = "[client]\n";
        $content .= 'user = '.escapeshellarg($username)."\n";
        $content .= 'password = '.escapeshellarg($password ?? '')."\n";
        $content .= 'host = '.escapeshellarg($host)."\n";
        $content .= 'port = '.escapeshellarg((string) $port)."\n";

        // Create file with restricted permissions
        File::put($this->mysqlConfigPath, $content);
        chmod($this->mysqlConfigPath, 0600); // Only owner can read/write

        $this->mysqlBasicCommand = 'mysql --defaults-extra-file='.escapeshellarg($this->mysqlConfigPath);
    }

    private function removeMysqlConfigFile(): void
    {
        if ($this->mysqlConfigPath && File::exists($this->mysqlConfigPath)) {
            // Securely delete the file
            File::delete($this->mysqlConfigPath);
            $this->mysqlConfigPath = null;
        }
    }

    private function getFileToImport(): ?string
    {
        if ($localFilePath = $this->option('import-from-local-file-path')) {
            return $this->processLocalFile($localFilePath);
        }

        return $this->downloadSqlData($this->option('source'));
    }

    private function processLocalFile(string $filePath): string
    {
        $this->validateFilePath($filePath);

        if (Str::endsWith($filePath, '.zip')) {
            $outputDir = escapeshellarg(dirname($filePath));
            $escapedPath = escapeshellarg($filePath);
            $this->executeShellCommand("unzip -o {$escapedPath} -d {$outputDir}", "Unzip File: {$filePath}");
            $filePath = Str::replaceLast('.zip', '.sql.gz', $filePath);
        }

        if (Str::endsWith($filePath, '.sql.gz')) {
            $escapedPath = escapeshellarg($filePath);
            $this->executeShellCommand("gunzip -f {$escapedPath}", "Gunzip File: {$filePath}");
            $filePath = Str::replaceLast('.sql.gz', '.sql', $filePath);
        }

        return $filePath;
    }

    private function validateFilePath(string $filePath): void
    {
        if (! File::exists($filePath)) {
            throw new RuntimeException("File does not exist: {$filePath}");
        }

        // Prevent path traversal attacks
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new RuntimeException("Invalid file path: {$filePath}");
        }

        // Only allow specific extensions
        $allowedExtensions = ['.sql', '.sql.gz', '.zip'];
        $hasAllowedExtension = false;
        foreach ($allowedExtensions as $ext) {
            if (Str::endsWith($realPath, $ext)) {
                $hasAllowedExtension = true;
                break;
            }
        }

        if (! $hasAllowedExtension) {
            throw new RuntimeException('Invalid file type. Only .sql, .sql.gz, and .zip files are allowed.');
        }
    }

    private function downloadSqlData(string $source): ?string
    {
        if (Str::contains($source, 'dump')) {
            return $this->dumpFromRemote($source);
        }

        return $this->downloadFromBackup();
    }

    private function dumpFromRemote(string $source): string
    {
        $info = 'Dumping database from remote server (this will block the database temporarily)';
        $optionNoData = Str::contains($source, 'structure') ? ' --no-data' : '';

        $isStaging = Str::startsWith($source, 'staging');
        $host = $isStaging ? config('database-downloader.staging_server') : config('database-downloader.server');
        $sshUser = $isStaging ? config('database-downloader.staging_ssh_user') : config('database-downloader.ssh_user');

        $this->validateRemoteConfig($host, $sshUser);

        $localFile = $this->localPath.'/'.escapeshellarg($this->dbName).'.sql';
        $remoteConfig = escapeshellarg("/etc/{$this->tenant}/mysql-login-config.cnf");
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedHost = escapeshellarg($host);
        $escapedUser = escapeshellarg($sshUser);

        $command = "ssh {$escapedUser}@{$escapedHost} \"mysqldump --defaults-extra-file={$remoteConfig} --databases {$escapedDbName}{$optionNoData}\" > {$localFile}";

        $this->executeShellCommand($command, $info);

        return $localFile;
    }

    private function validateRemoteConfig(?string $host, ?string $sshUser): void
    {
        if (empty($host) || empty($sshUser)) {
            throw new RuntimeException('Remote server configuration is missing or invalid');
        }
    }

    private function downloadFromBackup(): ?string
    {
        $sshUser = config('database-downloader.backup_ssh_user');
        $host = config('database-downloader.backup_ssh_server');

        $this->validateRemoteConfig($host, $sshUser);

        $escapedUser = escapeshellarg($sshUser);
        $escapedHost = escapeshellarg($host);
        $escapedBackupPath = escapeshellarg($this->backupPath);

        $latestFileCommand = "ssh {$escapedUser}@{$escapedHost} \"ls -t {$escapedBackupPath} | head -1\"";
        $fileName = $this->executeShellCommand($latestFileCommand, 'Fetching latest backup filename');

        if (empty($fileName)) {
            $this->logAndOutputDebug('No backup file found');

            return null;
        }

        // Validate filename to prevent injection
        if (preg_match('/[;&|`$]/', $fileName)) {
            throw new RuntimeException('Invalid characters in backup filename');
        }

        $this->logAndOutputDebug("Downloading backup file: {$fileName}");
        $escapedRemotePath = escapeshellarg("{$this->backupPath}{$fileName}");
        $escapedLocalPath = escapeshellarg($this->localPath);
        $rsyncCommand = "rsync -vzrlptD {$escapedUser}@{$escapedHost}:{$escapedRemotePath} {$escapedLocalPath}";

        $output = [];
        $resultCode = -1;
        exec($rsyncCommand, $output, $resultCode);

        $this->logAndOutputInfo('Download completed with code: '.$resultCode);

        if ($resultCode > 0) {
            throw new RuntimeException('Failed to download backup file');
        }

        $escapedZipFile = escapeshellarg("{$this->localPath}{$fileName}");
        $this->executeShellCommand("unzip -o {$escapedZipFile} -d {$escapedLocalPath}", 'Extracting backup archive');

        $gzFile = escapeshellarg("{$this->localPath}db-dumps/mysql-{$this->dbName}.sql.gz");
        $this->executeShellCommand("gunzip -f {$gzFile}", 'Decompressing SQL file');

        return "{$this->localPath}db-dumps/mysql-{$this->dbName}.sql";
    }

    private function importDatabase(string $fileWithPath): void
    {
        if ($this->option('dropExisting')) {
            $this->dropDatabase();
        }

        $this->createDatabase();
        $this->importSqlFile($fileWithPath);
    }

    private function dropDatabase(): void
    {
        $escapedDbName = escapeshellarg($this->dbName);
        $command = "{$this->mysqlBasicCommand} --execute=\"DROP DATABASE IF EXISTS {$escapedDbName}\"";
        $this->executeShellCommand($command, "Dropping database: {$this->dbName}");
    }

    private function createDatabase(): void
    {
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedCharset = escapeshellarg($this->dbCharset);
        $escapedCollation = escapeshellarg($this->dbCollation);

        $command = "{$this->mysqlBasicCommand} --execute=\"CREATE DATABASE IF NOT EXISTS {$escapedDbName} DEFAULT CHARACTER SET {$escapedCharset} COLLATE {$escapedCollation}\"";
        $this->executeShellCommand($command, "Creating database: {$this->dbName}");
    }

    private function importSqlFile(string $fileWithPath): void
    {
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedFile = escapeshellarg($fileWithPath);

        $command = "{$this->mysqlBasicCommand} {$escapedDbName} < {$escapedFile}";
        $this->executeShellCommand($command, "Importing SQL file: {$fileWithPath}");
    }

    private function dispatchEvents(): void
    {
        DatabaseImported::dispatch($this->tenant, (bool) $this->option('files'));
    }

    private function cleanup(): void
    {
        $this->call('queue:prune-failed');

        if (class_exists('\Laravel\Telescope\TelescopeServiceProvider')) {
            $this->call('telescope:clear');
        }

        if (File::exists($this->localPath)) {
            File::deleteDirectory($this->localPath);
        }
    }

    private function executeShellCommand(string $command, ?string $info = null): ?string
    {
        if ($info) {
            $this->logAndOutputDebug($info);
        }

        if ($this->getOutput()->isVerbose()) {
            $this->line("Executing: {$command}");
        }

        $output = [];
        $resultCode = -1;
        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $errorMessage = 'Command failed with exit code '.$resultCode;
            if (! empty($output)) {
                $errorMessage .= ': '.implode(' ', $output);
            }
            throw new RuntimeException($errorMessage);
        }

        if ($this->getOutput()->isVerbose() && ! empty($output)) {
            $this->logAndOutputInfo('Output: '.implode(' ', $output));
        }

        return $output[0] ?? null;
    }

    private function logAndOutputError(string $error): void
    {
        $this->error($error);
        Log::error($error);
    }

    private function logAndOutputInfo(string $info): void
    {
        $this->info($info);
        Log::info($info);
    }

    private function logAndOutputDebug(string $line): void
    {
        $this->line($line);
        Log::debug($line);
    }
}
