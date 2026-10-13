<?php

use Topoff\DatabaseDownloader\Support\AnonymizationSqlBuilder;

/*
 * Runs the generated statements against a real MySQL server. Skipped unless a
 * server is configured, e.g.:
 *
 *   DB_DOWNLOADER_TEST_DSN="mysql:host=127.0.0.1;port=3306" DB_DOWNLOADER_TEST_USER=root composer test
 */

function anonymizationTestPdo(): PDO
{
    return new PDO(
        (string) getenv('DB_DOWNLOADER_TEST_DSN'),
        getenv('DB_DOWNLOADER_TEST_USER') ?: 'root',
        getenv('DB_DOWNLOADER_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
}

beforeEach(function (): void {
    if (! getenv('DB_DOWNLOADER_TEST_DSN')) {
        $this->markTestSkipped('DB_DOWNLOADER_TEST_DSN is not set.');
    }

    $this->database = 'dbdl_anon_test_'.bin2hex(random_bytes(4));
    $this->pdo = anonymizationTestPdo();
    $this->pdo->exec("CREATE DATABASE `{$this->database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $this->pdo->exec("USE `{$this->database}`");
    $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

    $this->pdo->exec('CREATE TABLE users (
        id INT PRIMARY KEY, is_admin TINYINT NULL, email VARCHAR(100) NULL, phone VARCHAR(20) NULL, address VARCHAR(100) NULL,
        UNIQUE KEY users_email_unique (email)
    )');
    $this->pdo->exec('CREATE TABLE sent_emails (id INT PRIMARY KEY, sender_email VARCHAR(255) NULL, recipient_email VARCHAR(255) NULL)');
    $this->pdo->exec('CREATE TABLE email_log (id INT PRIMARY KEY, `to` VARCHAR(100) NOT NULL)');

    $this->pdo->exec("INSERT INTO users VALUES
        (1, 0,    'anna@kunde.ch',        '+41 79 123 45 67', 'Bahnhofstrasse 1'),
        (2, 0,    NULL,                   '+41 44 765 43 21', 'Seeweg 9'),
        (3, NULL, 'bruno@kunde.ch',       '',                 NULL),
        (4, 1,    'admin@gmail.com',      '+41 79 000 00 01', 'Adminweg 1'),
        (5, 0,    'team@top-offerten.ch', '+41 79 000 00 02', 'Teamweg 2')");
    $this->pdo->exec("INSERT INTO sent_emails VALUES (1, 'team@top-offerten.ch', 'Anna@Kunde.ch ')");
    $this->pdo->exec("INSERT INTO email_log VALUES (1, 'anna@kunde.ch'), (2, '+41 79 123 45 67')");

    $this->builder = new AnonymizationSqlBuilder(
        columnOverrides: ['email_log' => ['to' => 'contact']],
        keepEmailDomains: ['top-offerten.ch'],
        keepRows: ['users' => 'is_admin = 1'],
    );

    $this->anonymize = function (string $salt = '0123456789abcdef0123456789abcdef'): void {
        $rows = $this->pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            ."WHERE TABLE_SCHEMA = '{$this->database}' ORDER BY TABLE_NAME, ORDINAL_POSITION"
        )->fetchAll();

        $columns = array_map(fn (array $row): array => [
            'table' => $row['TABLE_NAME'],
            'column' => $row['COLUMN_NAME'],
            'data_type' => $row['DATA_TYPE'],
            'length' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
            'generated' => false,
        ], $rows);

        foreach ($this->builder->detect($columns) as $table => $typed) {
            $this->pdo->exec($this->builder->build($table, $typed, $salt));
        }
    };
});

afterEach(function (): void {
    if (property_exists($this, 'pdo') && $this->pdo !== null && (property_exists($this, 'database') && $this->database !== null)) {
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

it('overwrites contact data and keeps exempt rows and values', function (): void {
    ($this->anonymize)();

    $users = $this->pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();

    // regular row: everything replaced
    expect($users[0]['email'])->toMatch('/^[a-f0-9]{16}@example\.test$/')
        ->and($users[0]['phone'])->toMatch('/^\+4100\d{12}$/')
        ->and($users[0]['address'])->toMatch('/^Musterstrasse \d{1,2}$/');

    // NULL email must not shield phone and address
    expect($users[1]['email'])->toBeNull()
        ->and($users[1]['phone'])->toStartWith('+4100')
        ->and($users[1]['address'])->toStartWith('Musterstrasse');

    // keep condition evaluating to NULL (is_admin NULL) does not exempt; empty values stay empty
    expect($users[2]['email'])->toEndWith('@example.test')
        ->and($users[2]['phone'])->toBe('')
        ->and($users[2]['address'])->toBeNull();

    // keep_rows: untouched
    expect($users[3])->toMatchArray(['email' => 'admin@gmail.com', 'phone' => '+41 79 000 00 01', 'address' => 'Adminweg 1']);

    // keep domain is per value: the email stays, the rest of the row is replaced
    expect($users[4]['email'])->toBe('team@top-offerten.ch')
        ->and($users[4]['phone'])->toStartWith('+4100')
        ->and($users[4]['address'])->toStartWith('Musterstrasse');
});

it('replaces the same address identically across tables and per value in mixed rows', function (): void {
    ($this->anonymize)();

    $anna = $this->pdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn();
    $sent = $this->pdo->query('SELECT * FROM sent_emails')->fetch();
    $log = $this->pdo->query('SELECT `to` FROM email_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    expect($sent['sender_email'])->toBe('team@top-offerten.ch')
        ->and($sent['recipient_email'])->toBe($anna)  // differs only in case / trailing space
        ->and($log[0])->toBe($anna)
        ->and($log[1])->toMatch('/^\+4100\d{12}$/');
});

it('can run again on already anonymized data', function (): void {
    ($this->anonymize)();
    ($this->anonymize)('ffffffffffffffffffffffffffffffff');

    $emails = $this->pdo->query('SELECT email FROM users WHERE id IN (1, 3) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    expect($emails[0])->toEndWith('@example.test')
        ->and($emails[0])->not->toBe($emails[1]);
});
