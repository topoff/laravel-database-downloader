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

The package provides an interactive command to download and import the database:

```bash
php artisan db:download
```

You'll be prompted to:
1. **Select the data source**: `backup`, `staging`, or `live`
2. **Select dump type** (if staging or live): `Full dump` or `Only structure`

### Options

- `--dropExisting`: Drop the database before import if it exists.
- `--files`: Download and import files from `public/storage`.
- `--dbName=`: Use a different database name instead of the default.
- `--import-from-local-file-path=`: Import from a specific local file instead of downloading.

### Events

After a successful import, the package dispatches a `Topoff\DatabaseDownloader\Events\DatabaseImported` event. You can listen to this event to perform post-import tasks like data sanitization or environment-specific adjustments.

```php
// In your EventServiceProvider or AppServiceProvider
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
