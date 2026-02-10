# Laravel Database Downloader - Development Guidelines

## Package Overview

This is a Laravel package that downloads and imports MySQL databases from production/staging/backup servers into local development environments.

## Architecture

### Core Components

- **DownloadDatabaseCommand**: Main command that handles database download and import
- **DatabaseImported Event**: Dispatched after successful import
- **Config**: `config/database-downloader.php` with server and connection settings

### Key Features

- Download from backup server (safest, no production impact)
- Direct dump from live/staging servers
- Structure-only imports
- Optional file imports
- Production environment protection
- Secure credential handling

## Code Standards

### Security First

1. **Input Validation**: All user inputs MUST be validated
   - Database names: Only alphanumeric, underscore, hyphen
   - Tenant names: Same restrictions
   - File paths: Whitelist extensions, prevent path traversal
   - Source parameter: Whitelist with ALLOWED_SOURCES constant

2. **Shell Command Escaping**: ALL shell arguments MUST be escaped with `escapeshellarg()`
   ```php
   // Good
   $escapedDbName = escapeshellarg($this->dbName);
   $command = "mysql {$escapedDbName}";
   
   // Bad
   $command = "mysql {$this->dbName}";
   ```

3. **MySQL Config Files**:
   - Random filenames (16 chars)
   - 0600 permissions (owner read/write only)
   - Always deleted in finally block
   - Credentials properly escaped

4. **Path Validation**:
   - Local paths must be within database directory
   - Use `realpath()` to prevent path traversal
   - Validate file extensions

### Code Quality

1. **Visibility**: Use `private` for all internal methods unless extension is needed
2. **Type Safety**: Strict type hints on all parameters and return types
3. **Single Responsibility**: One method, one purpose
4. **Error Handling**: 
   - Throw `RuntimeException` on failures
   - Log with stack traces
   - Return proper exit codes (self::SUCCESS / self::FAILURE)
5. **Naming**: Clear, descriptive method names (e.g., `validateDbName`, `createDatabase`)

### Testing

- Use Pest for all tests
- Test security validations (invalid inputs, path traversal)
- Test environment protection
- Use Testbench for Laravel integration tests
- Test data providers for multiple scenarios

### Dependencies

- PHP ^8.3
- Laravel ^11.0 || ^12.0
- Spatie Laravel Package Tools
- Orchestra Testbench for development

## Development Workflow

### Running Commands

```bash
# Using testbench
php vendor/bin/testbench db:download

# Using artisan script (for MCP servers)
php artisan db:download

# With options
php artisan db:download --source=live-dump --dropExisting
```

### Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test
vendor/bin/pest tests/DownloadDatabaseCommandTest.php
```

### Code Quality

```bash
# Format code
composer format

# Static analysis
composer analyse
```

### Boost Commands

```bash
# Generate guidelines
php artisan boost:guidelines

# Sync context
php artisan boost:sync
```

## Configuration

### Required Environment Variables

```env
DB_DOWNLOADER_SERVER=your-server.com
DB_DOWNLOADER_SSH_USER=your-ssh-user
DB_DOWNLOADER_STAGING_SERVER=staging.your-server.com
DB_DOWNLOADER_STAGING_SSH_USER=staging-user
DB_DOWNLOADER_BACKUP_SSH_SERVER=backup.server.com
DB_DOWNLOADER_BACKUP_SSH_USER=backup-user
DB_DOWNLOADER_BACKUP_PATH_TEMPLATE=/path/to/backups/{tenant}/{backup_name}/
```

### Config Structure

All configuration in `config/database-downloader.php`:
- Server configurations (live, staging, backup)
- SSH credentials
- MySQL connection
- Local paths

## Common Tasks

### Adding a New Validation

1. Create private `validateX()` method
2. Add regex or whitelist check
3. Throw `RuntimeException` with clear message
4. Call from `initializeConfig()` or relevant method
5. Add test case

### Adding a New Download Source

1. Add to `ALLOWED_SOURCES` constant
2. Implement in `downloadSqlData()` switch/if
3. Validate all inputs
4. Escape all shell arguments
5. Add test coverage

### Modifying Shell Commands

1. Never concatenate user input directly
2. Use `escapeshellarg()` for ALL variables
3. Store result in variable for debugging
4. Pass info message to `executeShellCommand()`
5. Let exception bubble up (don't catch and ignore)

## Anti-Patterns to Avoid

❌ **Don't**: Concatenate user input in shell commands
```php
$command = "mysql -u{$username} -p{$password}"; // WRONG
```

✅ **Do**: Use MySQL config files with escaped values
```php
$this->mysqlBasicCommand = 'mysql --defaults-extra-file='.escapeshellarg($this->mysqlConfigPath);
```

❌ **Don't**: Silently ignore errors
```php
try {
    $this->executeShellCommand($command);
} catch (Exception $e) {
    // Silent failure - BAD
}
```

✅ **Do**: Let exceptions propagate or handle explicitly
```php
try {
    $this->executeShellCommand($command);
    return self::SUCCESS;
} catch (Throwable $t) {
    $this->logAndOutputError('Error: '.$t->getMessage());
    return self::FAILURE;
}
```

❌ **Don't**: Use protected for everything
```php
protected function internalHelper() // WRONG if not meant for extension
```

✅ **Do**: Use private for internal methods
```php
private function validateInput() // CORRECT
```

## Security Checklist

Before committing changes:

- [ ] All user inputs validated
- [ ] All shell arguments escaped with `escapeshellarg()`
- [ ] No path traversal vulnerabilities
- [ ] MySQL config files have 0600 permissions
- [ ] Sensitive files cleaned up in finally block
- [ ] Production environment check in place
- [ ] Proper error logging (no sensitive data in logs)
- [ ] Tests cover security scenarios

## Integration with Laravel Boost

This package is configured to work with Laravel Boost in package development mode:

- `artisan` script delegates to Testbench
- WorkbenchServiceProvider configures paths for Boost commands
- CLAUDE.md (this file) contains package-specific guidelines
- MCP servers can access commands via artisan script

## Resources

- [Package Repository](https://github.com/topoff/laravel-database-downloader)
- [Testbench Documentation](https://packages.tools/testbench)
- [Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- [Security Documentation](SECURITY.md)

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3.28

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.
</laravel-boost-guidelines>
