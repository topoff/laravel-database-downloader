# Laravel Boost Integration - Changelog

## Added Features

### 🚀 Laravel Boost Support

The package is now fully compatible with Laravel Boost for enhanced AI-assisted development.

#### What's New

1. **Artisan Script** (`artisan`)
   - Delegates to Testbench for command execution
   - Enables MCP server integration
   - Makes all package commands available via standard artisan interface

2. **Workbench Service Provider Configuration**
   - Automatic path fixing for Boost commands
   - Base path set to package root
   - App path configured to `src/`
   - Guidelines path points to `CLAUDE.md`

3. **CLAUDE.md Guidelines**
   - Package-specific development guidelines
   - Security best practices
   - Code standards and conventions
   - Common tasks and anti-patterns
   - Integration instructions

4. **Documentation**
   - `BOOST.md`: Complete setup and usage guide
   - `SECURITY.md`: Security improvements and best practices
   - Updated `README.md` with Boost and security sections

#### How to Use

```bash
# Use commands via artisan script
php artisan db:download

# Boost commands will work when available
php artisan boost:guidelines
php artisan boost:sync
```

#### Technical Details

Based on [this article](https://denniskoch.dev/articles/2026-01-26-laravel-boost-for-package-development/), the integration:

- Listens to `CommandStarting` event
- Adjusts paths when Boost commands are detected
- Works seamlessly with Testbench package development

#### Files Added

- `artisan` - Command delegation script
- `CLAUDE.md` - AI guidelines
- `BOOST.md` - Integration documentation
- `SECURITY.md` - Security documentation
- `workbench/app/Providers/WorkbenchServiceProvider.php` - Path configuration

#### Files Modified

- `testbench.yaml` - Added WorkbenchServiceProvider and BoostServiceProvider
- `README.md` - Added Boost and Security sections

## Credits

Integration approach based on the excellent work by [Dennis Koch](https://denniskoch.dev/).
