<?php

declare(strict_types=1);

namespace Topoff\DatabaseDownloader\Support;

use RuntimeException;

/**
 * Decides which columns of an imported database hold contact data (email, phone,
 * street address) and builds the UPDATE statements that overwrite them with
 * placeholder values. Pure logic — it never talks to the database itself.
 *
 * Replacement values are derived from a salted SHA-256 of the original value, so
 * the same email gets the same placeholder in every table of one run (unique
 * indexes and joins on email keep working) without being reversible once the
 * per-run salt is gone.
 */
class AnonymizationSqlBuilder
{
    public const string TYPE_EMAIL = 'email';

    public const string TYPE_PHONE = 'phone';

    public const string TYPE_ADDRESS = 'address';

    /** Holds either an email or a phone number, decided per value. */
    public const string TYPE_CONTACT = 'contact';

    private const array TYPES = [self::TYPE_EMAIL, self::TYPE_PHONE, self::TYPE_ADDRESS, self::TYPE_CONTACT];

    private const array TEXT_DATA_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private const string EMAIL_PATTERN = '/^(.+_)?e?mail(_address)?(_backup)?$/';

    private const string PHONE_PATTERN = '/^(.+_)?(phone|mobile|telephone|tel)(_number)?(_backup)?$/';

    private const string ADDRESS_PATTERN = '/^(.+_)?(address|street|strasse)(_[0-9]+)?$/';

    private const string ADDRESS_EXCLUDE_PATTERN = '/(^|_)(ip|mac)_address(_[0-9]+)?$/';

    /** Hex characters of the hash used as the local part of a placeholder email. */
    private const int EMAIL_HASH_LENGTH = 16;

    /**
     * @param  array<string, array<string, string|false>>  $columnOverrides  table => [column => type|false]
     * @param  array<int, string>  $keepEmailDomains  emails ending in one of these domains stay untouched
     * @param  array<string, string>  $keepRows  table => SQL condition; matching rows stay untouched
     */
    public function __construct(
        private readonly string $emailDomain = 'example.test',
        private readonly array $columnOverrides = [],
        private readonly array $keepEmailDomains = [],
        private readonly array $keepRows = [],
    ) {
        $this->assertDomain($this->emailDomain);

        foreach ($this->keepEmailDomains as $domain) {
            $this->assertDomain($domain);
        }

        foreach ($this->columnOverrides as $table => $columns) {
            $this->assertIdentifier((string) $table);

            foreach ($columns as $column => $type) {
                $this->assertIdentifier((string) $column);

                if ($type !== false && ! in_array($type, self::TYPES, true)) {
                    throw new RuntimeException("Invalid anonymization type for {$table}.{$column}. Allowed: ".implode(', ', self::TYPES).', false.');
                }
            }
        }

        foreach (array_keys($this->keepRows) as $table) {
            $this->assertIdentifier((string) $table);
        }
    }

    /**
     * Pick the columns to anonymize out of a schema listing.
     *
     * @param  array<int, array{table: string, column: string, data_type: string, length: ?int, generated: bool}>  $columns
     * @return array<string, array<string, array{type: string, length: ?int}>> table => [column => type + max length]
     */
    public function detect(array $columns): array
    {
        $detected = [];

        foreach ($columns as $column) {
            $table = $column['table'];
            $name = $column['column'];
            $override = $this->columnOverrides[$table][$name] ?? null;

            if ($override === false) {
                continue;
            }

            $isText = in_array(strtolower($column['data_type']), self::TEXT_DATA_TYPES, true);

            if ($override !== null && (! $isText || $column['generated'])) {
                // A column that was configured explicitly must not be skipped silently.
                throw new RuntimeException("Configured anonymization column {$table}.{$name} is not a writable text column.");
            }

            if (! $isText || $column['generated']) {
                continue;
            }

            $type = $override ?? $this->detectType($name);

            if ($type === null) {
                continue;
            }

            $detected[$table][$name] = ['type' => $type, 'length' => $column['length']];
        }

        return $detected;
    }

    /**
     * Build the single UPDATE statement that overwrites all given columns of a table.
     *
     * @param  array<string, array{type: string, length: ?int}>  $typedColumns
     */
    public function build(string $table, array $typedColumns, string $salt): string
    {
        $this->assertIdentifier($table);

        if (! preg_match('/^[a-f0-9]{16,64}$/', $salt)) {
            throw new RuntimeException('Invalid anonymization salt.');
        }

        if ($typedColumns === []) {
            throw new RuntimeException("No columns to anonymize for table {$table}.");
        }

        $assignments = [];

        foreach ($typedColumns as $column => $definition) {
            $this->assertIdentifier($column);
            $assignments[] = $this->buildAssignment($table, $column, $definition['type'], $definition['length'], $salt);
        }

        $sql = "UPDATE `{$table}` SET ".implode(', ', $assignments);

        if (isset($this->keepRows[$table]) && trim($this->keepRows[$table]) !== '') {
            // COALESCE: a condition evaluating to NULL must not exempt the row.
            $sql .= ' WHERE COALESCE(('.$this->keepRows[$table].'), 0) = 0';
        }

        return $sql.';';
    }

    private function detectType(string $column): ?string
    {
        $name = strtolower($column);

        if (preg_match(self::EMAIL_PATTERN, $name)) {
            return self::TYPE_EMAIL;
        }

        if (preg_match(self::PHONE_PATTERN, $name)) {
            return self::TYPE_PHONE;
        }

        if (preg_match(self::ADDRESS_PATTERN, $name) && ! preg_match(self::ADDRESS_EXCLUDE_PATTERN, $name)) {
            return self::TYPE_ADDRESS;
        }

        return null;
    }

    private function buildAssignment(string $table, string $column, string $type, ?int $length, string $salt): string
    {
        $quoted = "`{$column}`";
        $hash = "SHA2(CONCAT('{$salt}', LOWER(TRIM({$quoted}))), 256)";

        $email = 'CONCAT(LEFT('.$hash.', '.self::EMAIL_HASH_LENGTH."), '@{$this->emailDomain}')";
        $phone = "CONCAT('+4100', LPAD(CAST(CONV(LEFT({$hash}, 10), 16, 10) AS UNSIGNED) % 1000000000000, 12, '0'))";
        $address = "CONCAT('Musterstrasse ', 1 + CAST(CONV(LEFT({$hash}, 4), 16, 10) AS UNSIGNED) % 99)";

        $holdsEmail = in_array($type, [self::TYPE_EMAIL, self::TYPE_CONTACT], true);

        if ($holdsEmail && $length !== null && $length < self::EMAIL_HASH_LENGTH + 1 + strlen($this->emailDomain)) {
            // Truncating placeholder emails would make distinct addresses collide.
            throw new RuntimeException("Column {$table}.{$column} is too short ({$length}) for a placeholder email.");
        }

        $replacement = match ($type) {
            self::TYPE_EMAIL => $email,
            self::TYPE_PHONE => $phone,
            self::TYPE_ADDRESS => $address,
            self::TYPE_CONTACT => "IF({$quoted} LIKE '%@%', {$email}, {$phone})",
            default => throw new RuntimeException("Invalid anonymization type for {$table}.{$column}."),
        };

        if ($length !== null) {
            $replacement = "LEFT({$replacement}, {$length})";
        }

        $keep = "{$quoted} IS NULL OR {$quoted} = ''";

        if ($holdsEmail) {
            foreach ($this->keepEmailDomains as $domain) {
                $keep .= " OR {$quoted} LIKE '%@{$domain}'";
            }
        }

        return "{$quoted} = CASE WHEN {$keep} THEN {$quoted} ELSE {$replacement} END";
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            throw new RuntimeException("Invalid MySQL identifier: {$identifier}");
        }
    }

    private function assertDomain(string $domain): void
    {
        if (! preg_match('/^[a-z0-9.-]{1,60}$/', $domain)) {
            throw new RuntimeException("Invalid email domain: {$domain}");
        }
    }
}
