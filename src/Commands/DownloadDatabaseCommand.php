<?php

namespace Topoff\DatabaseDownloader\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
        $this->components->info('Chosen Command Options:');
        $this->components->twoColumnDetail('Source', $this->option('source'));
        $this->components->twoColumnDetail('Drop Existing', $this->option('dropExisting') ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Import Files', $this->option('files') ? 'Yes' : 'No');
        $this->newLine();
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
        $this->components->task('Clearing cache', fn () => $this->call('cache:clear'));
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

    private function getDatabaseCredentials(string $dbConnection): array
    {
        return [
            'username' => config("database.connections.{$dbConnection}.username"),
            'password' => config("database.connections.{$dbConnection}.password"),
            'host' => config("database.connections.{$dbConnection}.host"),
            'port' => config("database.connections.{$dbConnection}.port"),
        ];
    }

    private function validateCredentials(array $credentials): void
    {
        if (empty($credentials['username']) || empty($credentials['host'])) {
            throw new RuntimeException('Database credentials are not configured properly');
        }
    }

    private function buildMysqlConfigContent(array $credentials): string
    {
        return implode("\n", [
            '[client]',
            'user = '.escapeshellarg($credentials['username']),
            'password = '.escapeshellarg($credentials['password'] ?? ''),
            'host = '.escapeshellarg($credentials['host']),
            'port = '.escapeshellarg((string) $credentials['port']),
            '',
        ]);
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

        $existingFile = $this->handleExistingFiles();
        if ($existingFile !== null) {
            return $existingFile;
        }

        $this->components->task('Preparing local directory', fn () => $this->ensureLocalDirectoryExists());

        return $this->downloadSqlData($this->option('source'));
    }

    /**
     * Check for existing files and prompt user for action.
     *
     * @return string|null Returns file path to use, or null to download fresh
     */
    private function handleExistingFiles(): ?string
    {
        $existingFiles = $this->getExistingFiles();

        if (empty($existingFiles)) {
            return null;
        }

        $choices = array_merge(
            array_map(fn (string $file) => "Use: {$file}", $existingFiles),
            [
                'Enter custom path',
                'Ignore and download fresh',
            ]
        );

        $choice = $this->components->choice(
            'Found existing files. What would you like to do?',
            $choices,
            0
        );

        if ($choice === 'Ignore and download fresh') {
            return null;
        }

        if ($choice === 'Enter custom path') {
            $customPath = $this->components->ask('Enter the path to the SQL file');

            return $this->processLocalFile($customPath);
        }

        // Extract file path from choice (remove "Use: " prefix)
        $selectedFile = Str::after($choice, 'Use: ');

        return $this->processLocalFile($selectedFile);
    }

    /**
     * Get existing downloaded files from the local path.
     *
     * @return array<string>
     */
    private function getExistingFiles(): array
    {
        $files = [];

        if (! File::exists($this->localPath)) {
            return $files;
        }

        // Check for zip files
        foreach (File::glob("{$this->localPath}*.zip") as $file) {
            $files[] = $file;
        }

        // Check for extracted SQL files in db-dumps directory
        $dbDumpsPath = "{$this->localPath}db-dumps/";
        if (File::exists($dbDumpsPath)) {
            foreach (File::glob("{$dbDumpsPath}*.sql") as $file) {
                $files[] = $file;
            }
            foreach (File::glob("{$dbDumpsPath}*.sql.gz") as $file) {
                $files[] = $file;
            }
        }

        // Check for SQL files directly in local path
        foreach (File::glob("{$this->localPath}*.sql") as $file) {
            $files[] = $file;
        }
        foreach (File::glob("{$this->localPath}*.sql.gz") as $file) {
            $files[] = $file;
        }

        return array_unique($files);
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

        if (! Str::endsWith($realPath, $allowedExtensions)) {
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
        $this->components->warn('⚠️  Remote dump will temporarily block the database');

        $isStaging = Str::startsWith($source, 'staging');
        $config = $this->getRemoteServerConfig($isStaging);
        $this->validateRemoteConfig($config['host'], $config['ssh_user']);

        $optionNoData = Str::contains($source, 'structure') ? ' --no-data' : '';
        $localFile = $this->localPath.'/'.$this->dbName.'.sql';

        $command = $this->buildRemoteDumpCommand($config, $optionNoData, $localFile);

        $this->components->task(
            "Dumping from {$config['host']}",
            fn () => $this->executeShellCommand($command)
        );

        return $localFile;
    }

    private function getRemoteServerConfig(bool $isStaging): array
    {
        $prefix = $isStaging ? 'staging_' : '';

        return [
            'host' => config("database-downloader.{$prefix}server"),
            'ssh_user' => config("database-downloader.{$prefix}ssh_user"),
        ];
    }

    private function buildRemoteDumpCommand(array $config, string $optionNoData, string $localFile): string
    {
        $remoteConfig = escapeshellarg("/etc/{$this->tenant}/mysql-login-config.cnf");
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedHost = escapeshellarg($config['host']);
        $escapedUser = escapeshellarg($config['ssh_user']);
        $escapedLocalFile = escapeshellarg($localFile);

        return "ssh {$escapedUser}@{$escapedHost} \"mysqldump --defaults-extra-file={$remoteConfig} --databases {$escapedDbName}{$optionNoData}\" > {$escapedLocalFile}";
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
        $fileName = $this->executeShellCommand($latestFileCommand);

        if (empty($fileName)) {
            $this->components->warn('No backup file found');

            return null;
        }

        // Validate filename to prevent injection
        if (preg_match('/[;&|`$]/', $fileName)) {
            throw new RuntimeException('Invalid characters in backup filename');
        }

        // Prepare escaped paths
        $escapedRemotePath = escapeshellarg("{$this->backupPath}{$fileName}");
        $escapedLocalPath = escapeshellarg($this->localPath);

        // Download backup file
        $rsyncCommand = "rsync -vzrlptD {$escapedUser}@{$escapedHost}:{$escapedRemotePath} {$escapedLocalPath}";

        $this->components->task("Downloading File with Command: {$rsyncCommand}", function () use ($rsyncCommand, $escapedUser, $escapedHost, $escapedRemotePath, $escapedLocalPath) {
            $output = [];
            $resultCode = -1;
            exec($rsyncCommand, $output, $resultCode);

            if ($resultCode > 0) {
                throw new RuntimeException('Failed to download backup file');
            }
        });

        // Extract and decompress
        $escapedZipFile = escapeshellarg("{$this->localPath}{$fileName}");
        $this->components->task('Extracting backup archive',
            fn () => $this->executeShellCommand("unzip -o {$escapedZipFile} -d {$escapedLocalPath}")
        );

        $gzFile = escapeshellarg("{$this->localPath}db-dumps/mysql-{$this->dbName}.sql.gz");
        $this->components->task('Decompressing SQL file',
            fn () => $this->executeShellCommand("gunzip -f {$gzFile}")
        );

        return "{$this->localPath}db-dumps/mysql-{$this->dbName}.sql";
    }

    private function importDatabase(string $fileWithPath): void
    {
        $this->components->info('Importing database');

        if ($this->option('dropExisting')) {
            $this->components->task(
                'Dropping existing database',
                fn () => $this->dropDatabase()
            );
        }

        $this->components->task(
            'Creating database',
            fn () => $this->createDatabase()
        );

        $this->importSqlFile($fileWithPath);
    }

    private function dropDatabase(): void
    {
        $safeDbName = $this->escapeMysqlIdentifier($this->dbName);
        $command = "{$this->mysqlBasicCommand} --execute=\"DROP DATABASE IF EXISTS {$safeDbName}\"";
        $this->executeShellCommand($command);
    }

    private function createDatabase(): void
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
     * Backticks are escaped for shell interpretation.
     */
    private function escapeMysqlIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            throw new RuntimeException("Invalid MySQL identifier: {$identifier}");
        }

        return "\`{$identifier}\`";
    }

    private function importSqlFile(string $fileWithPath): void
    {
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedFile = escapeshellarg($fileWithPath);

        if ($this->isPvAvailable()) {
            $this->importSqlFileWithProgress($escapedFile, $escapedDbName);
        } else {
            $this->components->task(
                'Importing SQL file',
                function () use ($escapedDbName, $escapedFile) {
                    $command = "{$this->mysqlBasicCommand} {$escapedDbName} < {$escapedFile}";
                    $this->executeShellCommand($command);
                }
            );
        }
    }

    private function isPvAvailable(): bool
    {
        exec('which pv 2>/dev/null', $output, $resultCode);

        return $resultCode === 0;
    }

    private function importSqlFileWithProgress(string $escapedFile, string $escapedDbName): void
    {
        $this->components->twoColumnDetail('Importing SQL file', '<fg=yellow>IN PROGRESS</>');

        $command = "pv -p -e -t -a {$escapedFile} | {$this->mysqlBasicCommand} {$escapedDbName}";

        if ($this->getOutput()->isVerbose()) {
            $this->components->twoColumnDetail('Command', $command);
        }

        $resultCode = 0;
        passthru($command, $resultCode);

        if ($resultCode !== 0) {
            throw new RuntimeException("SQL import failed with exit code {$resultCode}");
        }
    }

    private function dispatchEvents(): void
    {
        DatabaseImported::dispatch($this->tenant, (bool) $this->option('files'));
    }

    private function cleanup(): void
    {
        $this->components->info('Cleaning up');

        $this->components->task('Pruning failed queue jobs', fn () => $this->call('queue:prune-failed'));

        if (class_exists('\Laravel\Telescope\TelescopeServiceProvider')) {
            $this->components->task('Clearing Telescope data', fn () => $this->call('telescope:clear'));
        }

        if (File::exists($this->localPath)) {
            $this->components->task('Removing temporary files', fn () => File::deleteDirectory($this->localPath));
        }
    }

    private function executeShellCommand(string $command): ?string
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

        if ($this->getOutput()->isVerbose() && ! empty($output)) {
            $this->components->bulletList($output);
        }

        return $output[0] ?? null;
    }

    private function logCommandFailure(int $exitCode, array $output): never
    {
        $errorMessage = "Command failed with exit code {$exitCode}";

        if (! empty($output)) {
            $errorMessage .= ":\n".implode("\n", $output);
        }

        throw new RuntimeException($errorMessage);
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
