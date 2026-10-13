<?php

use Topoff\DatabaseDownloader\Support\AnonymizationSqlBuilder;

function anonymizationColumn(string $column, string $dataType = 'varchar', ?int $length = 100, bool $generated = false, string $table = 'users'): array
{
    return ['table' => $table, 'column' => $column, 'data_type' => $dataType, 'length' => $length, 'generated' => $generated];
}

const ANONYMIZATION_TEST_SALT = '0123456789abcdef0123456789abcdef';

it('detects contact columns by name', function (string $column, string $type): void {
    $detected = new AnonymizationSqlBuilder()->detect([anonymizationColumn($column)]);

    expect($detected['users'][$column]['type'] ?? null)->toBe($type);
})->with([
    ['email', 'email'],
    ['stripe_email', 'email'],
    ['email_backup', 'email'],
    ['new_email', 'email'],
    ['email_address', 'email'],
    ['phone', 'phone'],
    ['phone_backup', 'phone'],
    ['trustedshops_mobile', 'phone'],
    ['phone_number', 'phone'],
    ['address', 'address'],
    ['address_2', 'address'],
    ['delivery_address', 'address'],
    ['street', 'address'],
]);

it('does not detect look-alike columns', function (string $column, string $dataType): void {
    expect(new AnonymizationSqlBuilder()->detect([anonymizationColumn($column, $dataType)]))->toBe([]);
})->with([
    ['email_invalid_reason', 'varchar'],
    ['receipt_mail_class', 'varchar'],
    ['phone_prefix', 'varchar'],
    ['ip_address', 'varchar'],
    ['mac_address', 'varchar'],
    ['mobile', 'tinyint'],
    ['email_verified_at', 'datetime'],
    ['name', 'varchar'],
]);

it('skips generated columns', function (): void {
    expect(new AnonymizationSqlBuilder()->detect([anonymizationColumn('email', generated: true)]))->toBe([]);
});

it('applies column overrides: add, retype and exclude', function (): void {
    $builder = new AnonymizationSqlBuilder(columnOverrides: [
        'email_log' => ['to' => 'contact'],
        'users' => ['email_backup' => false, 'note' => 'phone'],
    ]);

    $detected = $builder->detect([
        anonymizationColumn('to', table: 'email_log'),
        anonymizationColumn('email'),
        anonymizationColumn('email_backup'),
        anonymizationColumn('note'),
    ]);

    expect($detected)->toBe([
        'email_log' => ['to' => ['type' => 'contact', 'length' => 100]],
        'users' => [
            'email' => ['type' => 'email', 'length' => 100],
            'note' => ['type' => 'phone', 'length' => 100],
        ],
    ]);
});

it('refuses an explicitly configured column that is not a text column', function (): void {
    $builder = new AnonymizationSqlBuilder(columnOverrides: ['users' => ['mobile' => 'phone']]);

    expect(fn (): array => $builder->detect([anonymizationColumn('mobile', 'tinyint', null)]))
        ->toThrow(RuntimeException::class, 'not a writable text column');
});

it('builds one update per table with a null-safe keep condition', function (): void {
    $builder = new AnonymizationSqlBuilder(
        keepEmailDomains: ['example.org'],
        keepRows: ['users' => 'is_admin = 1'],
    );

    $sql = $builder->build('users', [
        'email' => ['type' => 'email', 'length' => 100],
        'phone' => ['type' => 'phone', 'length' => 50],
        'address' => ['type' => 'address', 'length' => 100],
    ], ANONYMIZATION_TEST_SALT);

    expect(substr_count($sql, 'UPDATE `users` SET'))->toBe(1)
        ->and($sql)->toContain("`email` LIKE '%@example.org'")
        ->and($sql)->not->toContain("`phone` LIKE '%@example.org'")
        ->and($sql)->toContain('@example.test')
        ->and($sql)->toContain('Musterstrasse')
        ->and($sql)->toEndWith('WHERE COALESCE((is_admin = 1), 0) = 0;');
});

it('accepts table names with hyphens', function (): void {
    $sql = new AnonymizationSqlBuilder()->build('my-table', ['email' => ['type' => 'email', 'length' => 100]], ANONYMIZATION_TEST_SALT);

    expect($sql)->toStartWith('UPDATE `my-table` SET');
});

it('rejects invalid identifiers, domains, types and salts', function (Closure $callback): void {
    expect($callback)->toThrow(RuntimeException::class);
})->with([
    'table' => [fn (): string => new AnonymizationSqlBuilder()->build('users`; DROP', ['email' => ['type' => 'email', 'length' => 100]], ANONYMIZATION_TEST_SALT)],
    'column' => [fn (): string => new AnonymizationSqlBuilder()->build('users', ['e`mail' => ['type' => 'email', 'length' => 100]], ANONYMIZATION_TEST_SALT)],
    'salt' => [fn (): string => new AnonymizationSqlBuilder()->build('users', ['email' => ['type' => 'email', 'length' => 100]], "x' OR 1=1")],
    'domain' => [fn (): AnonymizationSqlBuilder => new AnonymizationSqlBuilder("example.test' --")],
    'keep domain' => [fn (): AnonymizationSqlBuilder => new AnonymizationSqlBuilder(keepEmailDomains: ['%'])],
    'override type' => [fn (): AnonymizationSqlBuilder => new AnonymizationSqlBuilder(columnOverrides: ['users' => ['x' => 'name']])],
    'override table' => [fn (): AnonymizationSqlBuilder => new AnonymizationSqlBuilder(columnOverrides: ['us ers' => ['x' => 'email']])],
]);

it('refuses to truncate placeholder emails', function (): void {
    expect(fn (): string => new AnonymizationSqlBuilder()->build('users', ['email' => ['type' => 'email', 'length' => 20]], ANONYMIZATION_TEST_SALT))
        ->toThrow(RuntimeException::class, 'too short');
});
