<?php

namespace Topoff\DatabaseDownloader\Tests\Fixtures;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Topoff\DatabaseDownloader\Commands\DownloadDatabaseCommand;

/**
 * The real command with download and import cut out, so the steps around them
 * (anonymization, events, failure handling) can run without ssh or mysql.
 */
class FakeDownloadDatabaseCommand extends DownloadDatabaseCommand
{
    public static bool $failImport = false;

    public static bool $importCalled = false;

    /** Simulate --import-from-local-file-path: the file is the user's own, not a download. */
    public static bool $ownImportFile = false;

    public static string $dumpContent = "INSERT INTO `users` VALUES (1);\n";

    /** Run the real lookup of existing files; only the download itself is faked. */
    public static bool $realFileLookup = false;

    public static bool $downloaded = false;

    /** @var array<int, string> Content of every file the import received, in order. */
    public static array $importedContents = [];

    public function __construct()
    {
        $this->signature = str_replace('db:download', 'db:download-fake', $this->signature);

        parent::__construct();
    }

    public static function reset(): void
    {
        self::$failImport = false;
        self::$importCalled = false;
        self::$ownImportFile = false;
        self::$dumpContent = "INSERT INTO `users` VALUES (1);\n";
        self::$realFileLookup = false;
        self::$downloaded = false;
        self::$importedContents = [];
    }

    public static function localPath(): string
    {
        return sys_get_temp_dir().'/dbdl-fake-dumps/';
    }

    #[\Override]
    protected function prepare(): void
    {
        $this->dbName = 'testdb';
        $this->localPath = self::localPath();
        $this->mysqlBasicCommand = 'mysql';

        if ($this->option('table')) {
            $this->table = $this->validateTableName($this->option('table'));
        }
    }

    #[\Override]
    protected function getFileToImport(): ?string
    {
        if (self::$realFileLookup) {
            return parent::getFileToImport();
        }

        File::ensureDirectoryExists($this->localPath);
        File::put($this->localPath.'testdb.sql', self::$dumpContent);
        $this->dumpFilesInUse = ! self::$ownImportFile;

        return $this->localPath.'testdb.sql';
    }

    #[\Override]
    protected function downloadSqlData(string $source): string
    {
        self::$downloaded = true;
        File::put($this->localPath.'testdb.sql', self::$dumpContent);

        return $this->localPath.'testdb.sql';
    }

    #[\Override]
    protected function importDatabase(string $fileWithPath): void
    {
        self::$importCalled = true;
        self::$importedContents = array_map(File::get(...), [$fileWithPath, ...$this->additionalImportFiles]);

        if (self::$failImport) {
            throw new RuntimeException('import broke');
        }
    }
}
