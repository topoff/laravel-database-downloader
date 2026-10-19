<?php

namespace Topoff\DatabaseDownloader\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Topoff\DatabaseDownloader\Concerns\InteractsWithMysql;
use Topoff\DatabaseDownloader\Events\DatabaseImported;
use Topoff\DatabaseDownloader\Support\AnonymizationSqlBuilder;

class DownloadDatabaseCommand extends Command
{
    use InteractsWithMysql;

    protected $signature = 'db:download
    {--source=backup : Data source: backup|staging-dump|staging-dump-structure|live-dump|live-dump-structure}
    {--dbName= : Use a different database name}
    {--import-from-local-file-path= : Import from a specific local file instead of downloading}
    {--use-existing : Import already downloaded dump files regardless of their age, without asking}
    {--dropExisting : Drop the database before import if it exists}
    {--files : Pass filesImported=true to the DatabaseImported event (an app listener imports the files)}
    {--table= : Import only a specific table}';

    protected $description = 'Import the live database from the live or backup server to local';

    protected string $localPath;

    protected string $backupPath;

    protected string $source;

    protected ?string $table = null;

    /**
     * Further SQL files to import after the primary one, in order. Filled when a
     * backup archive contains multiple dumps (spatie three-dump split: small-backup /
     * heavy-schema / heavy-data) — importing only one of them silently produced a
     * database with nothing but messages + message_log.
     *
     * @var array<int, string>
     */
    protected array $additionalImportFiles = [];

    /**
     * Cached per-host result of the remote dump-binary / flag probe.
     * Keyed by "{ssh_user}@{host}" so probing different remotes during one run stays correct.
     *
     * @var array<string, array{binary: string, flags: string}>
     */
    protected array $remoteDumpConfigCache = [];

    /** Dump files (real data) are on disk under the local path. */
    protected bool $dumpFilesInUse = false;

    protected bool $importStarted = false;

    protected bool $anonymizationCompleted = false;

    public function handle(): int
    {
        if (! $this->canRunInCurrentEnvironment()) {
            $this->logAndOutputError('This command cannot be executed in production environment');

            return self::FAILURE;
        }

        $this->dumpFilesInUse = false;
        $this->importStarted = false;
        $this->anonymizationCompleted = false;

        $this->source = $this->determineSource();

        if ($this->getOutput()->isVerbose()) {
            $this->logStart();
        }

        $this->trapAbortSignals();

        try {
            $this->prepare();

            $fileToImport = $this->getFileToImport();

            if (in_array($fileToImport, [null, '', '0'], true) || ! File::exists($fileToImport)) {
                $this->logAndOutputInfo('No file to import found');

                return self::FAILURE;
            }

            if ($this->table !== null) {
                $fileToImport = $this->filterSqlFilesForTable([$fileToImport, ...$this->additionalImportFiles]);
                $this->additionalImportFiles = [];
            }

            $this->assertDumpsTargetDatabase([$fileToImport, ...$this->additionalImportFiles]);

            $this->importStarted = true;
            $this->importDatabase($fileToImport);
            $this->anonymizePersonalData();
            $this->dispatchEvents();

            if (! $this->cleanup()) {
                return self::FAILURE;
            }

            $this->logAndOutputInfo('Database import completed successfully');
            Log::debug('Finished '.static::class);

            return self::SUCCESS;
        } catch (Throwable $t) {
            $this->components->error('Error: '.$t->getMessage());

            // Clean up first: a failing logger must not leave real data behind.
            $this->handleFailureWithPersonalData();

            rescue(fn () => Log::error('Database import failed', [
                'exception' => $t,
                'trace' => $t->getTraceAsString(),
            ]), report: false);

            return self::FAILURE;
        } finally {
            $this->removeMysqlConfigFile();
        }
    }

    /**
     * Ctrl+C or a kill must not skip the clean-up either: anonymize what got
     * imported, remove the dumps and the credentials file, then exit.
     */
    protected function trapAbortSignals(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        $this->trap([SIGINT, SIGTERM], function (int $signal): never {
            $this->components->error('Aborted by signal '.$signal);
            $this->handleFailureWithPersonalData();
            $this->removeMysqlConfigFile();

            exit(128 + $signal);
        });
    }

    protected function determineSource(): string
    {
        // If source is provided via option or running non-interactively, use it
        if ($this->option('source') || $this->option('no-interaction')) {
            return $this->option('source') ?? 'backup';
        }

        $environment = $this->components->choice(
            'Select the data source',
            ['backup', 'staging', 'live'],
            'backup'
        );

        if ($environment === 'backup') {
            return 'backup';
        }

        $dumpType = $this->components->choice(
            'Select dump type',
            ['Full dump', 'Only structure'],
            'Full dump'
        );

        $structureSuffix = $dumpType === 'Only structure' ? '-structure' : '';

        return "{$environment}-dump{$structureSuffix}";
    }

    protected function logStart(): void
    {
        $this->components->info('Chosen Command Options:');
        $this->components->twoColumnDetail('Source', $this->source);
        $this->components->twoColumnDetail('Drop Existing', $this->option('dropExisting') ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Import Files', $this->option('files') ? 'Yes' : 'No');
        if ($this->table !== null) {
            $this->components->twoColumnDetail('Table', $this->table);
        }
        $this->newLine();
    }

    protected function prepare(): void
    {
        $this->initializeConfig();
        $this->callSilently('cache:clear');
    }

    protected function ensureLocalDirectoryExists(): void
    {
        // The directory only holds dumps of earlier runs; a fresh download replaces them.
        // Left-overs would get mixed into the new import, so a failed delete aborts.
        // deleteDirectory() returns true even when single files could not be removed.
        if (File::exists($this->localPath)) {
            File::deleteDirectory($this->localPath);
            clearstatcache();

            if (is_dir($this->localPath)) {
                throw new RuntimeException("Could not clear the local download directory: {$this->localPath}");
            }
        }

        File::makeDirectory($this->localPath, 0755, true, true);
    }

    protected function initializeConfig(): void
    {
        $dbConnection = config('database-downloader.mysql_connection', 'mysql');

        $this->localPath = $this->validateLocalPath(config('database-downloader.local_path'));
        $this->dbName = $this->validateDbName(
            $this->option('dbName') ?? config("database.connections.{$dbConnection}.database")
        );
        // Validated up front: an invalid value must fail before --dropExisting drops anything.
        $this->dbCharset = $this->escapeMysqlIdentifier(config("database.connections.{$dbConnection}.charset", 'utf8mb4'));
        $this->dbCollation = $this->escapeMysqlIdentifier(config("database.connections.{$dbConnection}.collation", 'utf8mb4_unicode_ci'));

        if ($this->option('table')) {
            $this->table = $this->validateTableName($this->option('table'));
        }

        $this->backupPath = $this->buildBackupPath();
        $this->createMysqlConfigFile($dbConnection);
    }

    protected function validateLocalPath(string $path): string
    {
        if ($path === '' || $path === '0') {
            throw new RuntimeException('Local path cannot be empty');
        }

        // The directory gets deleted recursively: it must lie strictly inside the
        // database directory. "." / ".." segments would escape the dirname() check.
        if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new RuntimeException('Local path must not contain "." or ".." segments');
        }

        $path = Str::finish($path, '/');
        $databasePath = Str::finish(realpath(database_path()) ?: database_path(), '/');

        // An existing directory is checked by its real path too: through a symlink the
        // recursive delete would run in the link target.
        $candidates = [Str::finish(realpath(dirname($path)) ?: dirname($path), '/').basename($path).'/'];

        if (($realPath = realpath($path)) !== false) {
            $candidates[] = Str::finish($realPath, '/');
        }

        foreach ($candidates as $candidate) {
            if ($candidate === $databasePath || ! Str::startsWith($candidate, $databasePath)) {
                throw new RuntimeException('Local path must be within the database directory');
            }
        }

        return $path;
    }

    protected function validateTableName(string $tableName): string
    {
        if ($tableName === '' || $tableName === '0') {
            throw new RuntimeException('Table name cannot be empty');
        }

        if (preg_match('/[^a-zA-Z0-9_-]/', $tableName)) {
            throw new RuntimeException('Invalid table name. Only alphanumeric, underscore, and hyphen allowed.');
        }

        return $tableName;
    }

    protected function buildBackupPath(): string
    {
        $path = str_replace(
            '{backup_name}',
            config('backup.backup.name', 'default'),
            config('database-downloader.backup_path_template')
        );

        return Str::finish($path, '/');
    }

    protected function getFileToImport(): ?string
    {
        if ($localFilePath = $this->option('import-from-local-file-path')) {
            // A .zip / .sql.gz is unpacked into the local path: those copies are ours to
            // clean up — unless the given file itself lies in there.
            $this->dumpFilesInUse = Str::endsWith($localFilePath, ['.zip', '.sql.gz'])
                && ! Str::startsWith(realpath($localFilePath) ?: $localFilePath, Str::finish(realpath($this->localPath) ?: $this->localPath, '/'));

            return $this->processLocalFile($localFilePath);
        }

        // Set before unpacking: a failed unzip/gunzip must not leave real data behind either.
        $this->dumpFilesInUse = true;

        $existingFile = $this->handleExistingFiles();
        if ($existingFile !== null) {
            return $existingFile;
        }

        $this->ensureLocalDirectoryExists();

        return $this->downloadSqlData($this->source);
    }

    /**
     * Check for existing files and prompt user for action.
     *
     * @return string|null Returns file path to use, or null to download fresh
     */
    protected function handleExistingFiles(): ?string
    {
        $existingFiles = $this->getExistingFiles();

        if ($existingFiles === []) {
            return null;
        }

        // Stale dumps are replaced silently by a fresh download (ensureLocalDirectoryExists
        // clears the directory) unless --use-existing asks for them explicitly.
        if (! $this->option('use-existing') && $this->existingFilesAreStale($existingFiles)) {
            $this->components->info(sprintf(
                'Existing dump files are older than %d hours, downloading fresh',
                $this->existingFilesMaxAgeHours()
            ));

            return null;
        }

        // One choice for all files: they belong together (a backup holds several dumps,
        // importing just one of them leaves the database incomplete).
        if (! $this->option('no-interaction') && ! $this->option('use-existing')) {
            $choice = $this->components->choice(
                'Found existing dump files ('.implode(', ', array_map(basename(...), $existingFiles)).'). What would you like to do?',
                ['Use existing files', 'Enter custom path', 'Ignore and download fresh'],
                0
            );

            if ($choice === 'Ignore and download fresh') {
                return null;
            }

            if ($choice === 'Enter custom path') {
                return $this->processLocalFile((string) $this->components->ask('Enter the path to the SQL file'));
            }
        }

        return $this->useExistingFiles($existingFiles);
    }

    /**
     * A left-behind backup archive wins over extracted files: an aborted run may
     * have unpacked only part of it. Without an archive all dumps are imported.
     *
     * @param  array<string>  $existingFiles
     */
    protected function useExistingFiles(array $existingFiles): string
    {
        $zipFiles = array_values(array_filter($existingFiles, fn (string $file): bool => Str::endsWith($file, '.zip')));

        if ($zipFiles !== []) {
            rsort($zipFiles);

            return $this->extractBackupArchive($zipFiles[0]);
        }

        sort($existingFiles);
        $processed = array_values(array_unique(array_map($this->processLocalFile(...), $existingFiles)));
        $this->additionalImportFiles = array_slice($processed, 1);

        return $processed[0];
    }

    /**
     * The oldest file decides: rsync keeps the backup's mtime and unzip/gunzip keep
     * the archive's, so the file age is the age of the backup itself.
     *
     * @param  array<string>  $files
     */
    protected function existingFilesAreStale(array $files): bool
    {
        $oldest = min(array_map(File::lastModified(...), $files));

        return $oldest < time() - $this->existingFilesMaxAgeHours() * 3600;
    }

    protected function existingFilesMaxAgeHours(): int
    {
        return (int) config('database-downloader.existing_files_max_age_hours', 24);
    }

    /**
     * Get existing downloaded files from the local path.
     * Files are sorted with .sql first (ready to import), then .sql.gz, then .zip.
     *
     * @return array<string>
     */
    protected function getExistingFiles(): array
    {
        $sqlFiles = [];
        $gzFiles = [];
        $zipFiles = [];

        if (! File::exists($this->localPath)) {
            return [];
        }

        // Check for extracted SQL files in db-dumps directory
        $dbDumpsPath = "{$this->localPath}db-dumps/";
        if (File::exists($dbDumpsPath)) {
            foreach (File::glob("{$dbDumpsPath}*.sql") as $file) {
                $sqlFiles[] = $file;
            }
            foreach (File::glob("{$dbDumpsPath}*.sql.gz") as $file) {
                $gzFiles[] = $file;
            }
        }

        // Check for SQL files directly in local path
        foreach (File::glob("{$this->localPath}*.sql") as $file) {
            $sqlFiles[] = $file;
        }
        foreach (File::glob("{$this->localPath}*.sql.gz") as $file) {
            $gzFiles[] = $file;
        }

        // Check for zip files
        foreach (File::glob("{$this->localPath}*.zip") as $file) {
            $zipFiles[] = $file;
        }

        // Return sorted: .sql first (ready to import), then .sql.gz, then .zip
        return array_unique(array_merge($sqlFiles, $gzFiles, $zipFiles));
    }

    protected function processLocalFile(string $filePath): string
    {
        $this->validateFilePath($filePath);

        if (Str::endsWith($filePath, '.zip')) {
            return $this->extractBackupArchive($filePath);
        }

        // Decompressed into the local path, so the given file stays untouched.
        if (Str::endsWith($filePath, '.sql.gz')) {
            File::ensureDirectoryExists($this->localPath);
            $target = $this->localPath.basename($filePath, '.gz');
            $this->executeShellCommand('gunzip -c '.escapeshellarg($filePath).' > '.escapeshellarg($target));

            return $target;
        }

        return $filePath;
    }

    protected function validateFilePath(string $filePath): void
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

    protected function downloadSqlData(string $source): ?string
    {
        if (Str::contains($source, 'dump')) {
            return $this->dumpFromRemote($source);
        }

        return $this->downloadFromBackup();
    }

    protected function dumpFromRemote(string $source): string
    {
        $this->components->warn('⚠️  Remote dump will temporarily block the database');

        // In non-interactive mode, skip confirmation
        if (! $this->option('no-interaction') && ! $this->components->confirm('Do you want to continue?', false)) {
            throw new RuntimeException('Remote dump cancelled by user');
        }

        $isStaging = Str::startsWith($source, 'staging');
        $config = $this->getRemoteServerConfig($isStaging);
        $this->validateRemoteConfig($config['host'], $config['ssh_user']);

        $optionNoData = Str::contains($source, 'structure') ? ' --no-data' : '';
        $localFile = $this->localPath.'/'.$this->dbName.'.sql';
        ['binary' => $dumpBinary, 'flags' => $mysqldumpFlags] = $this->detectRemoteDumpConfig($config);

        $command = $this->buildRemoteDumpCommand($config, $optionNoData, $localFile, $dumpBinary, $mysqldumpFlags);

        $this->components->task(
            "Dumping from {$config['host']}",
            fn (): ?string => $this->executeShellCommand($command)
        );

        return $localFile;
    }

    protected function getRemoteServerConfig(bool $isStaging): array
    {
        $prefix = $isStaging ? 'staging_' : 'live_';

        return [
            'host' => config("database-downloader.{$prefix}server"),
            'ssh_user' => config("database-downloader.{$prefix}ssh_user"),
            'mysql_config_path' => config("database-downloader.{$prefix}mysql_config_path"),
        ];
    }

    protected function buildRemoteDumpCommand(array $config, string $optionNoData, string $localFile, string $dumpBinary = 'mysqldump', string $mysqldumpFlags = ''): string
    {
        $remoteConfig = escapeshellarg((string) $config['mysql_config_path']);
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedHost = escapeshellarg((string) $config['host']);
        $escapedUser = escapeshellarg((string) $config['ssh_user']);
        $escapedLocalFile = escapeshellarg($localFile);

        if ($this->table !== null) {
            $escapedTable = escapeshellarg($this->table);

            return "ssh {$escapedUser}@{$escapedHost} \"{$dumpBinary} --defaults-extra-file={$remoteConfig}{$mysqldumpFlags} {$escapedDbName} {$escapedTable}{$optionNoData}\" > {$escapedLocalFile}";
        }

        return "ssh {$escapedUser}@{$escapedHost} \"{$dumpBinary} --defaults-extra-file={$remoteConfig}{$mysqldumpFlags} --databases {$escapedDbName}{$optionNoData}\" > {$escapedLocalFile}";
    }

    /**
     * Probe the remote once to discover which dump binary and version-specific flags to use.
     *
     * - Prefers `mariadb-dump` when present (silences the "Deprecated program name" warning
     *   that MariaDB's `mysqldump` symlink prints to stderr on every invocation).
     * - Adds `--column-statistics=0` only when the chosen binary's --help advertises it. The flag
     *   was added in MySQL 8's mysqldump to suppress optimizer column-stats queries that fail
     *   against pre-8 servers; MariaDB's dumper does not know the option and aborts with
     *   "unknown variable".
     * - Always adds `--no-tablespaces`. Since MySQL 5.7/8.0 mysqldump tries to dump tablespace
     *   info, which requires the global PROCESS privilege that backup/dump users typically lack
     *   ("Access denied; you need (at least one of) the PROCESS privilege(s)"). The flag skips
     *   that step (irrelevant for ordinary data/structure dumps) and is supported by both binaries.
     *
     * @return array{binary: string, flags: string}
     */
    protected function detectRemoteDumpConfig(array $config): array
    {
        $cacheKey = $config['ssh_user'].'@'.$config['host'];

        if (isset($this->remoteDumpConfigCache[$cacheKey])) {
            return $this->remoteDumpConfigCache[$cacheKey];
        }

        $escapedHost = escapeshellarg((string) $config['host']);
        $escapedUser = escapeshellarg((string) $config['ssh_user']);

        $probeScript = 'if command -v mariadb-dump >/dev/null 2>&1; then '
            .'echo BIN=mariadb-dump; '
            .'echo COL=no; '
            .'else '
            .'echo BIN=mysqldump; '
            .'mysqldump --help 2>/dev/null | grep -q -- --column-statistics && echo COL=yes || echo COL=no; '
            .'fi';

        $probe = "ssh {$escapedUser}@{$escapedHost} ".escapeshellarg($probeScript);

        $output = [];
        exec($probe, $output);

        $result = $this->parseRemoteDumpProbe($output);

        if ($this->getOutput()->isVerbose()) {
            $this->components->twoColumnDetail(
                "Remote dump binary on {$config['host']}",
                $result['binary'].($result['flags'] !== '' ? ' (with column-statistics)' : '')
            );
        }

        return $this->remoteDumpConfigCache[$cacheKey] = $result;
    }

    /**
     * Parse the lines emitted by the remote probe script.
     *
     * @param  array<int, string>  $output
     * @return array{binary: string, flags: string}
     */
    protected function parseRemoteDumpProbe(array $output): array
    {
        $binary = 'mysqldump';
        $supportsColumnStatistics = false;

        foreach ($output as $line) {
            if ($line === 'BIN=mariadb-dump' || $line === 'BIN=mysqldump') {
                $binary = Str::after($line, 'BIN=');
            } elseif ($line === 'COL=yes') {
                $supportsColumnStatistics = true;
            } elseif ($line === 'COL=no') {
                $supportsColumnStatistics = false;
            }
        }

        return [
            'binary' => $binary,
            'flags' => ' --no-tablespaces'.($supportsColumnStatistics ? ' --column-statistics=0' : ''),
        ];
    }

    protected function validateRemoteConfig(?string $host, ?string $sshUser): void
    {
        if (in_array($host, [null, '', '0'], true) || in_array($sshUser, [null, '', '0'], true)) {
            throw new RuntimeException('Remote server configuration is missing or invalid');
        }
    }

    protected function downloadFromBackup(): ?string
    {
        $sshUser = config('database-downloader.backup_ssh_user');
        $host = config('database-downloader.backup_ssh_server');

        $this->validateRemoteConfig($host, $sshUser);

        $escapedUser = escapeshellarg((string) $sshUser);
        $escapedHost = escapeshellarg((string) $host);
        $escapedBackupPath = escapeshellarg($this->backupPath);

        $latestFileCommand = "ssh {$escapedUser}@{$escapedHost} \"ls -t {$escapedBackupPath} | head -1\"";
        $fileName = $this->executeShellCommand($latestFileCommand);

        if (in_array($fileName, [null, '', '0'], true)) {
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

        $this->components->task("Downloading File with Command: {$rsyncCommand}", function () use ($rsyncCommand): void {
            $output = [];
            $resultCode = -1;
            exec($rsyncCommand, $output, $resultCode);

            if ($resultCode > 0) {
                throw new RuntimeException('Failed to download backup file');
            }
        });

        return $this->extractBackupArchive("{$this->localPath}{$fileName}");
    }

    /**
     * Unpack a downloaded backup archive: its dumps land in db-dumps/ inside the
     * local path (not next to the archive), all of them are imported.
     */
    protected function extractBackupArchive(string $zipFile): string
    {
        $this->validateFilePath($zipFile);
        File::ensureDirectoryExists($this->localPath);

        // Only this archive's dumps may be imported: no old or half-extracted left-overs.
        // Only dump files go (the archive itself may lie in there); a symlink is just unlinked.
        $dbDumpsPath = "{$this->localPath}db-dumps";

        if (is_link($dbDumpsPath)) {
            unlink($dbDumpsPath);
        } else {
            File::delete([...File::glob("{$dbDumpsPath}/*.sql"), ...File::glob("{$dbDumpsPath}/*.sql.gz")]);
        }

        $escapedZipFile = escapeshellarg($zipFile);
        $escapedLocalPath = escapeshellarg($this->localPath);
        $this->components->task('Extracting backup archive',
            fn (): ?string => $this->executeShellCommand("unzip -o {$escapedZipFile} -d {$escapedLocalPath}")
        );

        $gzFiles = $this->selectBackupDumps(File::glob("{$this->localPath}db-dumps/*.sql.gz"));

        if (count($gzFiles) > 1) {
            $this->components->info('Backup contains '.count($gzFiles).' dumps, importing all: '.implode(', ', array_map(basename(...), $gzFiles)));
        }

        $sqlFiles = [];
        foreach ($gzFiles as $gzFile) {
            $escapedGzFile = escapeshellarg($gzFile);
            $this->components->task('Decompressing '.basename($gzFile),
                fn (): ?string => $this->executeShellCommand("gunzip -f {$escapedGzFile}")
            );
            $sqlFiles[] = Str::replaceLast('.gz', '', $gzFile);
        }

        $this->additionalImportFiles = array_slice($sqlFiles, 1);

        return $sqlFiles[0];
    }

    /**
     * Decide which dump files of a backup archive to import.
     *
     * Backups may contain MULTIPLE dumps of the same database (spatie three-dump
     * split: mysql-small-backup + mysql-heavy-schema + mysql-heavy-data). All of
     * them must be imported — importing only one silently produces an incomplete
     * database. Sorted alphabetically the split imports in the right order: the
     * heavy dumps create their tables first, and the small-backup dump — which
     * carries the VIEW definitions referencing them — runs last.
     *
     * A file matching the legacy single-dump name `mysql-{db}.sql.gz` keeps the
     * old exact-match behavior.
     *
     * @param  array<int, string>  $gzFiles
     * @return array<int, string>
     */
    protected function selectBackupDumps(array $gzFiles): array
    {
        if ($gzFiles === []) {
            throw new RuntimeException("No .sql.gz file found in {$this->localPath}db-dumps/");
        }

        foreach ($gzFiles as $file) {
            if (Str::contains($file, "mysql-{$this->dbName}.sql.gz")) {
                return [$file];
            }
        }

        sort($gzFiles);

        return $gzFiles;
    }

    /**
     * Filter the given SQL files for the requested table; the first file that
     * contains it wins. With multi-dump backups the table can live in any of them.
     *
     * @param  array<int, string>  $candidates
     */
    protected function filterSqlFilesForTable(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            try {
                return $this->filterSqlFileForTable($candidate);
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException("Table '{$this->table}' was not found in any SQL file");
    }

    protected function filterSqlFileForTable(string $fileWithPath): string
    {
        // Own directory inside the local path: never the input file itself, not picked
        // up as an existing dump by the next run, and removed with the other dumps.
        $filteredFile = "{$this->localPath}table-filter/{$this->table}-".Str::random(8).'.sql';
        File::ensureDirectoryExists(dirname($filteredFile));

        $escapedInput = escapeshellarg($fileWithPath);
        $escapedOutput = escapeshellarg($filteredFile);

        $escapedTable = escapeshellarg((string) $this->table);

        // A table section starts with its "Table structure" comment or its DROP TABLE
        // (dumps with --skip-comments) and ends at the marker of the next table.
        $command = "awk -v tbl={$escapedTable} '"
            .'/^-- Table structure for table `/ || /^DROP TABLE IF EXISTS `/ { '
            .'if (index($0, "`" tbl "`")) { found=1 } else if (found) { exit } } '
            ."found { print }' {$escapedInput} > {$escapedOutput}";

        $this->components->task(
            "Filtering SQL file for table '{$this->table}'",
            fn (): ?string => $this->executeShellCommand($command)
        );

        if (! File::exists($filteredFile) || File::size($filteredFile) === 0) {
            throw new RuntimeException("Table '{$this->table}' was not found in the SQL file");
        }

        return $filteredFile;
    }

    protected function importDatabase(string $fileWithPath): void
    {
        if ($this->table !== null) {
            $this->components->info("Importing table '{$this->table}' into database '{$this->dbName}'");
        } else {
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
        }

        $this->importSqlFile($fileWithPath);

        foreach ($this->additionalImportFiles as $additionalFile) {
            $this->components->info('Importing additional dump: '.basename($additionalFile));
            $this->importSqlFile($additionalFile);
        }
    }

    protected function importSqlFile(string $fileWithPath): void
    {
        $escapedDbName = escapeshellarg($this->dbName);
        $escapedFile = escapeshellarg($fileWithPath);
        $definerFilter = $this->buildDefinerStripFilter();

        if ($this->isPvAvailable()) {
            $this->importSqlFileWithProgress($escapedFile, $escapedDbName, $definerFilter);
        } else {
            $this->info('If you want to see the progress, install pv with: brew install pv');
            $this->components->task(
                'Importing SQL file',
                function () use ($escapedDbName, $escapedFile, $definerFilter): void {
                    $command = "{$definerFilter} < {$escapedFile} | {$this->mysqlBasicCommand} {$escapedDbName}";
                    $this->executeShellCommand($this->withPipefail($command));
                }
            );
        }
    }

    protected function isPvAvailable(): bool
    {
        exec('which pv 2>/dev/null', $output, $resultCode);

        return $resultCode === 0;
    }

    protected function importSqlFileWithProgress(string $escapedFile, string $escapedDbName, string $definerFilter): void
    {
        $this->components->twoColumnDetail('Importing SQL file', '<fg=yellow>IN PROGRESS</>');

        $command = "pv -p -e -t -a {$escapedFile} | {$definerFilter} | {$this->mysqlBasicCommand} {$escapedDbName}";

        if ($this->getOutput()->isVerbose()) {
            $this->components->twoColumnDetail('Command', $command);
        }

        $resultCode = 0;
        passthru($this->withPipefail($command), $resultCode);

        if ($resultCode !== 0) {
            throw new RuntimeException("SQL import failed with exit code {$resultCode}");
        }
    }

    /**
     * Without pipefail only mysql's exit code counts: an unreadable dump would end
     * as a "successful" import of nothing.
     */
    protected function withPipefail(string $command): string
    {
        return 'bash -o pipefail -c '.escapeshellarg($command);
    }

    /**
     * Shell filter that strips DEFINER clauses from a SQL dump stream.
     *
     * Dumps taken on another server embed the creator user (e.g. `forge`@`%`)
     * as the DEFINER of views, triggers, and routines. Importing those on a
     * server where the MySQL user lacks the SUPER / SET_USER_ID privilege
     * fails with error 1227. Removing DEFINER and downgrading SQL SECURITY
     * to INVOKER makes the dump portable across environments.
     */
    protected function buildDefinerStripFilter(): string
    {
        // INSERT / REPLACE lines carry the row data: left untouched, so no stored text gets rewritten.
        return "LC_ALL=C sed -E '/^(INSERT|REPLACE) /!{s/DEFINER=`[^`]+`@`[^`]+`//g; s/SQL SECURITY DEFINER/SQL SECURITY INVOKER/g;}'";
    }

    /**
     * Anonymization is on unless configured otherwise. A missing key (stale config
     * cache from before the option existed) falls back to the same rule as the
     * config default: everywhere except staging.
     */
    protected function shouldAnonymize(): bool
    {
        return (bool) (config('database-downloader.anonymize') ?? ! App::environment('staging'));
    }

    /**
     * Dumps created with `--databases` carry CREATE DATABASE / USE statements. If
     * they name another database than the import target (`--dbName`), the data
     * would land there — and the anonymization would run on the wrong schema.
     *
     * @param  array<int, string>  $files
     */
    protected function assertDumpsTargetDatabase(array $files): void
    {
        foreach ($files as $file) {
            $lines = [];
            exec('LC_ALL=C grep -a -i -E '.escapeshellarg('^[[:space:]]*(USE|CREATE[[:space:]]+(DATABASE|SCHEMA))[[:space:]]').' '.escapeshellarg($file), $lines);

            foreach ($lines as $line) {
                $database = $this->parseTargetDatabase($line);

                if ($database !== $this->dbName) {
                    throw new RuntimeException(
                        'The dump '.basename($file)." writes into database '".($database ?? trim($line))."', but the import target is '{$this->dbName}'. "
                        .'Import aborted. Use a matching --dbName or a dump without CREATE DATABASE / USE statements.'
                    );
                }
            }
        }
    }

    /**
     * Extract the database name from a USE / CREATE DATABASE line; null if it cannot be read.
     */
    protected function parseTargetDatabase(string $line): ?string
    {
        $pattern = '/^\s*(?:USE|CREATE\s+(?:DATABASE|SCHEMA)(?:\s*\/\*.*?\*\/)?(?:\s+IF\s+NOT\s+EXISTS)?)\s*(?:`([^`]+)`|([^\s;`]+))/i';

        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : $matches[2];
    }

    /**
     * Overwrite contact data (email, phone, street address) in the imported
     * database with placeholders, before any listener gets to see it.
     */
    protected function anonymizePersonalData(bool $continueOnError = false): void
    {
        if (! $this->shouldAnonymize()) {
            $this->components->warn('Anonymization is disabled: personal data is NOT overwritten.');

            return;
        }

        $this->components->info('Anonymizing personal data');

        $builder = new AnonymizationSqlBuilder(
            (string) config('database-downloader.anonymize_email_domain', 'example.test'),
            (array) config('database-downloader.anonymize_columns', []),
            (array) config('database-downloader.anonymize_keep_email_domains', []),
            (array) config('database-downloader.anonymize_keep_rows', []),
        );

        $tables = $builder->detect($this->fetchImportedColumns());

        // The salt lives only for this run: statements go to mysql via stdin.
        $salt = bin2hex(random_bytes(16));

        // Build everything first, so a configuration error surfaces before the first UPDATE.
        // In best-effort mode a table that cannot be built is skipped and reported instead.
        $statements = [];
        $failedTables = [];

        foreach ($tables as $table => $columns) {
            try {
                $statements[$table] = $builder->build($table, $columns, $salt);
            } catch (Throwable $t) {
                if (! $continueOnError) {
                    throw $t;
                }

                $failedTables[] = $table;
                $this->logAndOutputError("Anonymizing {$table} failed: ".$t->getMessage());
            }
        }

        foreach ($statements as $table => $statement) {
            try {
                $this->components->task(
                    "Anonymizing {$table} (".implode(', ', array_keys($tables[$table])).')',
                    function () use ($statement): void {
                        $this->runMysqlWithInput($statement);
                    }
                );
            } catch (Throwable $t) {
                if (! $continueOnError) {
                    throw $t;
                }

                $failedTables[] = $table;
                $this->logAndOutputError("Anonymizing {$table} failed: ".$t->getMessage());
            }
        }

        if ($failedTables !== []) {
            throw new RuntimeException('Not anonymized: '.implode(', ', $failedTables));
        }

        $this->anonymizationCompleted = true;
    }

    /**
     * List the columns of all base tables of the imported database.
     *
     * @return array<int, array{table: string, column: string, data_type: string, length: ?int, generated: bool}>
     */
    protected function fetchImportedColumns(): array
    {
        // dbName / table are validated to [a-zA-Z0-9_-], safe inside a string literal.
        $sql = 'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, IFNULL(c.CHARACTER_MAXIMUM_LENGTH, 0), '
            ."IF(IFNULL(c.GENERATION_EXPRESSION, '') = '', 0, 1) "
            .'FROM information_schema.COLUMNS c '
            .'JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME '
            ."WHERE c.TABLE_SCHEMA = '{$this->dbName}' AND t.TABLE_TYPE = 'BASE TABLE'";

        if ($this->table !== null) {
            $sql .= " AND c.TABLE_NAME = '{$this->table}'";
        }

        $sql .= ' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;';

        $columns = [];

        foreach ($this->runMysqlWithInput($sql, false) as $line) {
            $parts = explode("\t", $line);

            if (count($parts) !== 5) {
                throw new RuntimeException("Unexpected schema listing line: {$line}");
            }

            $columns[] = [
                'table' => $parts[0],
                'column' => $parts[1],
                'data_type' => $parts[2],
                'length' => (int) $parts[3] > 0 ? (int) $parts[3] : null,
                'generated' => $parts[4] === '1',
            ];
        }

        return $columns;
    }

    /**
     * A failed run must not leave real personal data behind unnoticed: anonymize
     * whatever got imported (best effort), say so if that was not possible, and
     * remove the downloaded dumps.
     */
    protected function handleFailureWithPersonalData(): void
    {
        if (! $this->shouldAnonymize()) {
            return;
        }

        // Also after a failed anonymization: retry once and carry on past a failing table.
        if ($this->importStarted && ! $this->anonymizationCompleted) {
            try {
                $this->anonymizePersonalData(continueOnError: true);
            } catch (Throwable $t) {
                $this->logAndOutputError('Anonymization failed: '.$t->getMessage());
            }
        }

        if ($this->importStarted && ! $this->anonymizationCompleted) {
            $this->logAndOutputError("Database '{$this->dbName}' may still contain real personal data. Re-run the import or drop the database.");
        }

        if ($this->dumpFilesInUse && File::exists($this->localPath)) {
            $this->removeDumpFiles();
        }

        $this->warnAboutLocalImportFile();
    }

    /**
     * Delete the downloaded dumps. deleteDirectory() can fail silently, so the
     * result is checked: left-over dumps with real data must not go unnoticed.
     */
    protected function removeDumpFiles(): bool
    {
        try {
            File::deleteDirectory($this->localPath);
        } catch (Throwable $t) {
            $this->components->error($t->getMessage());
        }

        if (File::exists($this->localPath)) {
            $this->logAndOutputError("Dump files could not be removed and may contain real personal data: {$this->localPath}");

            return false;
        }

        $this->components->info('Removed downloaded dump files');

        return true;
    }

    /**
     * A file passed via --import-from-local-file-path is the user's own and is never deleted.
     */
    protected function warnAboutLocalImportFile(): void
    {
        $localFilePath = $this->option('import-from-local-file-path');

        if (! $localFilePath || ! $this->shouldAnonymize()) {
            return;
        }

        // processLocalFile() may have unpacked it: foo.zip / foo.sql.gz -> foo.sql
        $candidates = array_unique([
            $localFilePath,
            Str::replaceLast('.sql.gz', '.sql', $localFilePath),
            Str::replaceLast('.zip', '.sql', $localFilePath),
        ]);

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                $this->components->warn("The import file still contains real personal data and was not deleted: {$candidate}");
            }
        }
    }

    protected function dispatchEvents(): void
    {
        DatabaseImported::dispatch((bool) $this->option('files'));
    }

    protected function cleanup(): bool
    {
        $this->components->info('Cleaning up');

        try {
            $failedDriver = config('queue.failed.driver');

            if (in_array($failedDriver, ['database', 'database-uuids'], true) && Schema::hasTable('failed_jobs')) {
                $this->components->task('Pruning failed queue jobs', fn () => $this->call('queue:prune-failed'));
            }
        } catch (Throwable $t) {
            $this->components->error($t->getMessage());
        }

        if (class_exists('\Laravel\Telescope\TelescopeServiceProvider')) {
            try {
                $this->components->task('Clearing Telescope data', fn () => $this->call('telescope:clear'));
            } catch (Throwable $t) {
                $this->components->error($t->getMessage());
            }
        }

        $removed = true;

        if (File::exists($this->localPath)) {
            // Dumps this run put there (real data) are always deleted while anonymization
            // is on. Otherwise — e.g. an own file passed via --import-from-local-file-path —
            // only in non-interactive mode or when confirmed.
            $mustDelete = $this->shouldAnonymize() && $this->dumpFilesInUse;

            if ($mustDelete || $this->option('no-interaction') || $this->components->confirm('Delete downloaded temporary files?', true)) {
                // Real data left on disk while anonymization is on fails the run.
                $removed = $this->removeDumpFiles() || ! $this->shouldAnonymize();
            } else {
                $this->components->info('Temporary files kept in: '.$this->localPath);
            }
        }

        $this->warnAboutLocalImportFile();

        return $removed;
    }
}
