# laravel-database-downloader

[![Latest Version on Packagist](https://img.shields.io/packagist/v/topoff/laravel-database-downloader.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-database-downloader)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-database-downloader/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/topoff/laravel-database-downloader/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/topoff/laravel-database-downloader/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/topoff/laravel-database-downloader/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/topoff/laravel-database-downloader.svg?style=flat-square)](https://packagist.org/packages/topoff/laravel-database-downloader)

This package allows you to easily dump and download a MySQL database from a production or backup server and import it into your local development environment. It also supports downloading files from storage and provides an event-driven way to handle post-import tasks.

## Installation

You can install the package via composer:

```bash
composer require topoff/laravel-database-downloader
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="database-downloader-config"
```

## Usage

The package provides a command to download and import the database:

```bash
php artisan db:download
```

By default it imports the latest backup (`--source=backup`).

### Options

- `--source=`: `backup` (default), `staging-dump`, `staging-dump-structure`, `live-dump` or
  `live-dump-structure`. A remote dump asks for confirmation (it briefly blocks the database).
- `--dropExisting`: Drop the database before import if it exists.
- `--files`: Dispatch `DatabaseImported` with `filesImported = true`; the package itself does
  not copy files — a listener in your app imports `public/storage`.
- `--table=`: Import only this table out of the dump.
- `--dbName=`: Use a different database name instead of the default.
- `--import-from-local-file-path=`: Import from a specific local file instead of downloading.
- `--use-existing`: Import the dump files an earlier run left in the local download directory,
  regardless of their age and without asking.

Dump files left in the local download directory by an earlier run are deleted and downloaded
fresh without asking when they are older than 24 hours (config `existing_files_max_age_hours`).
Younger files are offered for reuse — always all of them together (a backup holds several dumps),
and a left-behind backup archive is unpacked again rather than trusting partly extracted files.

A `.zip` or `.sql.gz` passed via `--import-from-local-file-path` is unpacked into the local
download directory; the file itself stays untouched.

### Creating the database

To create the database itself (a plain `CREATE DATABASE`) with the charset and
collation defined in `config/database.php`, use:

```bash
php artisan db:create
```

Laravel's `migrate` only applies the configured charset/collation when creating
**tables** — it does not create the database. This command fills that gap by
issuing `CREATE DATABASE IF NOT EXISTS ... DEFAULT CHARACTER SET ... COLLATE ...`
using the credentials of the configured connection.

#### Options

- `--dbName=`: Use a different database name instead of the configured one.
- `--charset=`: Override the charset (defaults to the connection charset).
- `--collation=`: Override the collation (defaults to the connection collation).
- `--dropExisting`: Drop the database first if it already exists.

Like `db:download`, this command refuses to run in the `production` environment.

### Anonymization

Right after the import — and before the `DatabaseImported` event — the command overwrites
contact data in the imported database, so no real personal data stays on development
machines. This is **on by default** in every environment except `staging`
(`DB_DOWNLOADER_ANONYMIZE=true|false` overrides the default; there is no CLI switch).

- **Detected by column name** (text columns only): email (`email`, `*_email`, `email_backup`, …),
  phone (`phone`, `*_phone`, `mobile`, `tel`, …) and street address (`address`, `address_2`,
  `street`, `*_address`, …; not `ip_address`).
- **Placeholders:** `<hash>@example.test`, `+4100…` (not dialable), `Musterstrasse <n>`.
  They are derived from a salted hash, so the same email gets the same placeholder in all
  tables of one run (unique indexes and joins on email keep working). The salt is random per
  run and not stored. `NULL` and empty values stay as they are.
- **Dump files are always deleted** while anonymization is on — also after a failed run,
  without asking. A file passed via `--import-from-local-file-path` is yours: it is kept and
  the command warns about it (inside the download directory you are asked first; in
  non-interactive mode that directory is cleared as before).
- **Failures:** if the import or the anonymization fails, the command anonymizes what was
  imported (best effort), exits with an error, does not dispatch `DatabaseImported` and tells
  you if the database may still contain real data.
- A dump containing `CREATE DATABASE` / `USE` for another database than the import target is
  rejected before the import (also with anonymization off).
- **Aborted runs** (Ctrl+C, `kill`) get the same clean-up as failed ones (needs `ext-pcntl`).
  A `kill` of only the PHP process takes effect once the running shell step (download, import)
  has ended; Ctrl+C stops that step right away.

Configure the rest in `config/database-downloader.php`:

```php
// columns the name detection cannot know, or wrong matches to exclude
'anonymize_columns' => [
    'email_log' => ['to' => 'contact'],            // 'email' | 'phone' | 'address' | 'contact'
    'users' => ['email_invalid_reason' => false],  // false = leave untouched
],
// email addresses of these domains are kept (per value; a value listing several addresses never is)
'anonymize_keep_email_domains' => ['your-company.com'],
// rows matching the SQL condition are kept entirely
'anonymize_keep_rows' => ['users' => 'is_admin = 1'],
```

**Limits:** only email, phone and street address are overwritten — names, postcodes, free
text and anything in JSON columns stay real. Columns with unusual names are not detected;
add them to `anonymize_columns`. Importing a single table (`--table`) uses a new salt, so its
placeholder emails no longer match those of an earlier full import.

### Events

After a successful import, the package dispatches a `Topoff\DatabaseDownloader\Events\DatabaseImported` event. You can listen to this event to perform post-import tasks like data sanitization or environment-specific adjustments.

```php
// Optionally Register the Listener in your EventServiceProvider or AppServiceProvider, Laravel does this automatically
Event::listen(
    \Topoff\DatabaseDownloader\Events\DatabaseImported::class,
    \App\Listeners\YourPostImportListener::class,
);
```

The event contains the following properties:
- `filesImported`: A boolean indicating if the `--files` option was used.

## Laravel Boost Integration

This package is configured to work with [Laravel Boost](https://github.com/laravel/boost) for enhanced AI-assisted development.

Key features:
- ✅ Artisan script for MCP server support
- ✅ WorkbenchServiceProvider with automatic path configuration
- ✅ CLAUDE.md with package-specific guidelines
- ✅ Security best practices and coding standards

For detailed setup and usage instructions, see [BOOST.md](BOOST.md).

## Security

This package has been thoroughly reviewed for security vulnerabilities:
- Input validation and sanitization
- Shell command injection prevention
- Path traversal protection
- Secure credential handling

For detailed security information, see [SECURITY.md](SECURITY.md).

## Development

### Code Quality Tools

This package uses several tools to maintain code quality:

#### Laravel Pint (Code Formatting)

Format code according to Laravel standards:

```bash
composer format
```

#### Rector (Automated Refactoring)

Preview potential code improvements:

```bash
composer rector-dry
```

Apply automated refactorings:

```bash
composer rector
```

#### PHPStan (Static Analysis)

Run static analysis:

```bash
composer analyse
```

#### Run All Quality Checks

```bash
composer lint
```

This runs both Pint and PHPStan.

### Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Andreas Berger](https://github.com/andreasberger83)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
