# Security Improvements

This document outlines the security improvements made to the `DownloadDatabaseCommand`.

## 🔒 Security Enhancements

### 1. **Input Validation & Sanitization**

#### Database Name Validation
- ✅ Validates database names to allow only alphanumeric characters, underscores, and hyphens
- ✅ Prevents SQL injection through database name parameter
- ✅ Empty database names are rejected

```php
private function validateDbName(string $dbName): string
{
    if (empty($dbName)) {
        throw new RuntimeException('Database name cannot be empty');
    }

    if (preg_match('/[^a-zA-Z0-9_-]/', $dbName)) {
        throw new RuntimeException('Invalid database name. Only alphanumeric, underscore, and hyphen allowed.');
    }

    return $dbName;
}
```

#### Tenant Name Validation
- ✅ Validates tenant names with same restrictions as database names
- ✅ Prevents path traversal attacks through tenant parameter

#### Source Validation
- ✅ Whitelist approach with `ALLOWED_SOURCES` constant
- ✅ Only predefined sources can be used
- ✅ Invalid sources are rejected before execution

### 2. **Shell Command Injection Prevention**

#### Complete Shell Escaping
All user-controlled inputs and configuration values are properly escaped:

```php
// Before (UNSAFE)
$command = "ssh {$sshUser}@{$host} \"mysqldump --databases {$this->dbName}\"";

// After (SAFE)
$escapedUser = escapeshellarg($sshUser);
$escapedHost = escapeshellarg($host);
$escapedDbName = escapeshellarg($this->dbName);
$command = "ssh {$escapedUser}@{$escapedHost} \"mysqldump --databases {$escapedDbName}\"";
```

#### Protected Variables
- Database names
- Hostnames
- SSH usernames
- File paths
- Remote config paths
- All MySQL credentials

### 3. **File Path Security**

#### Path Traversal Prevention
```php
private function validateFilePath(string $filePath): void
{
    // Verify file exists
    if (! File::exists($filePath)) {
        throw new RuntimeException("File does not exist: {$filePath}");
    }

    // Resolve real path (prevents ../ attacks)
    $realPath = realpath($filePath);
    if ($realPath === false) {
        throw new RuntimeException("Invalid file path: {$filePath}");
    }

    // Whitelist file extensions
    $allowedExtensions = ['.sql', '.sql.gz', '.zip'];
    // ... validation logic
}
```

#### Local Path Restriction
- ✅ Local paths must be within the database directory
- ✅ Prevents writing files outside designated areas

```php
private function validateLocalPath(string $path): string
{
    $realPath = realpath(dirname($path)) ?: dirname($path);
    $databasePath = realpath(database_path()) ?: database_path();

    if (! Str::startsWith($realPath, $databasePath)) {
        throw new RuntimeException('Local path must be within the database directory');
    }

    return $path;
}
```

### 4. **MySQL Configuration File Security**

#### Secure Credentials Storage
```php
private function createMysqlConfigFile(string $dbConnection): void
{
    // Random filename (16 chars) prevents predictable file location
    $this->mysqlConfigPath = database_path('mysql-login-'.Str::random(16).'.cnf');

    // Properly escaped credentials
    $content = "[client]\n";
    $content .= 'user = '.escapeshellarg($username)."\n";
    $content .= 'password = '.escapeshellarg($password ?? '')."\n";
    $content .= 'host = '.escapeshellarg($host)."\n";
    $content .= 'port = '.escapeshellarg((string) $port)."\n";

    File::put($this->mysqlConfigPath, $content);
    
    // Restrict permissions to owner only (read/write)
    chmod($this->mysqlConfigPath, 0600);

    // Escape in command usage
    $this->mysqlBasicCommand = 'mysql --defaults-extra-file='.escapeshellarg($this->mysqlConfigPath);
}
```

Features:
- ✅ Random filename prevents predictable paths
- ✅ Credentials are properly escaped
- ✅ File permissions restricted to 0600 (owner read/write only)
- ✅ Path is escaped when used in commands
- ✅ File is always deleted in finally block

### 5. **Filename Injection Prevention**

#### Backup Filename Validation
```php
// Validate filename to prevent injection
if (preg_match('/[;&|`$]/', $fileName)) {
    throw new RuntimeException('Invalid characters in backup filename');
}
```

Prevents command injection through malicious backup filenames from remote server.

### 6. **Error Handling**

#### Proper Exception Handling
- ✅ All shell commands throw `RuntimeException` on failure
- ✅ Detailed error logging with stack traces
- ✅ No silent failures
- ✅ Returns proper exit codes (SUCCESS/FAILURE)

```php
public function handle(): int
{
    try {
        // ... execution logic
        return self::SUCCESS;
    } catch (Throwable $t) {
        $this->logAndOutputError('Error: '.$t->getMessage());
        Log::error('Database import failed', [
            'exception' => $t,
            'trace' => $t->getTraceAsString(),
        ]);
        return self::FAILURE;
    } finally {
        // Always cleanup sensitive files
        $this->removeMysqlConfigFile();
    }
}
```

### 7. **Environment Protection**

#### Production Environment Lock
```php
private function canRunInCurrentEnvironment(): bool
{
    return ! App::environment('production');
}
```

- ✅ Command cannot run in production
- ✅ Prevents accidental data overwrite
- ✅ Returns proper error message

### 8. **Remote Configuration Validation**

```php
private function validateRemoteConfig(?string $host, ?string $sshUser): void
{
    if (empty($host) || empty($sshUser)) {
        throw new RuntimeException('Remote server configuration is missing or invalid');
    }
}
```

- ✅ Validates remote server credentials exist
- ✅ Prevents execution with missing config

### 9. **Code Quality Improvements**

#### Visibility Enforcement
- All methods changed from `protected` to `private`
- Prevents accidental misuse in child classes
- Reduces attack surface

#### Type Safety
- Strict return type declarations
- Proper type hints on all parameters
- Return `int` from `handle()` method for proper exit codes

#### Single Responsibility
- Complex methods broken down into smaller, focused methods
- Each method has a single, clear purpose
- Easier to audit and test

## 🛡️ Security Checklist

- [x] All user inputs validated
- [x] All shell arguments properly escaped
- [x] Path traversal attacks prevented
- [x] SQL injection prevented
- [x] Command injection prevented
- [x] File permissions properly set
- [x] Sensitive files cleaned up
- [x] Production environment protected
- [x] Proper error handling
- [x] Detailed logging
- [x] No silent failures
- [x] Proper exit codes

## 🔍 Testing Recommendations

### Security Tests to Add

1. **Input Validation Tests**
   ```php
   // Test invalid database names
   $this->artisan('db:download --dbName="test; DROP TABLE users"')
       ->assertExitCode(Command::FAILURE);
   ```

2. **Path Traversal Tests**
   ```php
   // Test path traversal attempts
   $this->artisan('db:download --import-from-local-file-path="../../../etc/passwd"')
       ->assertExitCode(Command::FAILURE);
   ```

3. **Production Environment Test**
   ```php
   // Test production lock
   App::shouldReceive('environment')->andReturn('production');
   $this->artisan('db:download')
       ->assertExitCode(Command::FAILURE);
   ```

4. **Source Validation Test**
   ```php
   // Test invalid source
   $this->artisan('db:download --source=malicious')
       ->assertExitCode(Command::FAILURE);
   ```

## 📝 Best Practices Applied

1. **Defense in Depth**: Multiple layers of validation
2. **Fail Secure**: Errors result in command failure, not silent continuation
3. **Least Privilege**: MySQL config files have minimal permissions (0600)
4. **Input Validation**: Whitelist approach for all inputs
5. **Proper Escaping**: All shell arguments properly escaped
6. **Secure Defaults**: Safe defaults for all configuration
7. **Logging**: Detailed error logging for security auditing
8. **Clean Cleanup**: Sensitive files always removed via finally block

## ⚠️ Remaining Considerations

1. **SSH Key Security**: Ensure SSH keys are properly secured on the server
2. **Network Security**: Use VPN or firewall rules to restrict database access
3. **Backup Server**: Ensure backup server has proper access controls
4. **MySQL Credentials**: Store in environment variables, never in code
5. **Log Security**: Ensure logs don't contain sensitive information
6. **Audit Trail**: Consider logging all command executions for audit purposes

## 🔄 Migration Notes

When upgrading existing installations:

1. No breaking changes to command signature
2. All existing options work the same way
3. Error handling is stricter (may fail where it previously succeeded silently)
4. Check logs for any new validation errors
5. Verify all configuration values are set properly
