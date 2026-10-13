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
    }

    #[\Override]
    protected function getFileToImport(): string
    {
        File::ensureDirectoryExists($this->localPath);
        File::put($this->localPath.'testdb.sql', self::$dumpContent);
        $this->dumpFilesInUse = ! self::$ownImportFile;

        return $this->localPath.'testdb.sql';
    }

    #[\Override]
    protected function importDatabase(string $fileWithPath): void
    {
        self::$importCalled = true;

        if (self::$failImport) {
            throw new RuntimeException('import broke');
        }
    }
}
