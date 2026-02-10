# Laravel Boost Integration

This package is configured to work with [Laravel Boost](https://github.com/laravel/boost) in package development mode.

## What's Included

### 1. Artisan Script

The `artisan` file in the package root delegates to Testbench's Laravel app, allowing MCP servers to execute commands:

```bash
php artisan db:download
php artisan boost:guidelines
php artisan boost:sync
```

### 2. Workbench Service Provider

The `WorkbenchServiceProvider` automatically configures Laravel Boost when Boost commands are executed:

- Sets the base path to the package root
- Configures the app path to `src/`
- Points guidelines to `CLAUDE.md` in the package root

This allows Boost to work correctly in a package development environment using Testbench.

### 3. Guidelines File (CLAUDE.md)

The `CLAUDE.md` file contains package-specific guidelines for AI assistants:

- Package architecture and structure
- Security best practices
- Code standards
- Common development tasks
- Anti-patterns to avoid

## How It Works

Based on [this article by Dennis Koch](https://denniskoch.dev/articles/2026-01-26-laravel-boost-for-package-development/), the integration works by:

1. **Path Fixing**: When a Boost command is detected, the WorkbenchServiceProvider adjusts the base path and app path to point to the package root instead of the Testbench vendor directory.

2. **Guidelines Configuration**: The guidelines path is updated to use the package's CLAUDE.md file.

3. **Artisan Delegation**: The artisan script delegates to Testbench's Laravel app, making commands available to MCP servers.

## Configuration

### testbench.yaml

The WorkbenchServiceProvider is registered in `testbench.yaml`:

```yaml
providers:
  - Workbench\App\Providers\WorkbenchServiceProvider
  - Laravel\Boost\BoostServiceProvider
```

### Boost Configuration

If you need to customize Boost settings, create a `workbench/config/boost.php` file or modify the existing one.

## Using with MCP Servers

### Setup

1. **Install Laravel Boost** (already in require-dev):
   ```bash
   composer require laravel/boost --dev
   ```

2. **Run Boost installation**:
   ```bash
   php artisan boost:install
   ```
   This will:
   - Configure AI guidelines (foundation, boost, php rules)
   - Set up skills for your AI agents
   - Install MCP servers (Boost, optionally Herd)
   - Create `.mcp.json` and `AGENTS.md` files

3. **The package is already configured!** All necessary files are in place:
   - `artisan` script (delegates to Workbench)
   - `WorkbenchServiceProvider` with path configuration
   - `CLAUDE.md` guidelines
   - `AGENTS.md` with link to CLAUDE.md

### Available Commands

Once set up, you can use all commands via the artisan script:

```bash
# Boost commands
php artisan boost:install      # Run installation wizard
php artisan boost:update       # Update guidelines & skills
php artisan boost:add-skill    # Add skills from GitHub
php artisan boost:mcp          # Start MCP server

# Your package commands
php artisan db:download --help
php artisan db:download --source=backup

# List all available commands
php artisan list
```

### MCP Configuration

If you're using MCP servers (like with Cursor, Claude Desktop, etc.), they can now execute commands through the `artisan` script:

```json
{
  "servers": {
    "laravel-database-downloader": {
      "command": "php",
      "args": ["artisan", "serve-mcp"],
      "cwd": "/path/to/laravel-database-downloader"
    }
  }
}
```

## Troubleshooting

### Boost Commands Not Available

If Boost commands aren't showing up:

1. **Check Installation**:
   ```bash
   composer show laravel/boost
   ```

2. **Verify Provider Registration** in `testbench.yaml`:
   ```yaml
   providers:
     - Laravel\Boost\BoostServiceProvider
   ```

3. **Clear Config Cache**:
   ```bash
   php artisan config:clear
   ```

4. **Check Environment**: Boost may require specific environment settings

### Paths Not Working

If Boost is using wrong paths:

1. **Verify WorkbenchServiceProvider** is loaded in `testbench.yaml`
2. **Check the path calculation** in `WorkbenchServiceProvider`:
   ```php
   $packageRoot = realpath(__DIR__.'/../../../');
   ```
   This should point to your package root (3 levels up from the provider)

3. **Test the path**:
   ```bash
   php artisan tinker
   >>> app()->basePath()
   >>> base_path('CLAUDE.md')
   ```

### Guidelines Not Found

If AI assistants aren't finding the guidelines:

1. **Verify CLAUDE.md exists** in package root
2. **Check the config**:
   ```php
   config('boost.code_environments.claude_code.guidelines_path')
   ```
3. **Make sure it runs when Boost commands execute**

## Development

When modifying the Boost integration:

1. **Update WorkbenchServiceProvider** if changing path logic
2. **Update CLAUDE.md** when adding new conventions or patterns
3. **Test with a Boost command**:
   ```bash
   php artisan boost:guidelines -v
   ```

## Resources

- [Laravel Boost Package Development Article](https://denniskoch.dev/articles/2026-01-26-laravel-boost-for-package-development/)
- [Laravel Boost GitHub](https://github.com/laravel/boost)
- [Orchestra Testbench](https://packages.tools/testbench)
- [MCP Protocol](https://modelcontextprotocol.io/)

## Credits

The integration approach is based on the excellent article by [Dennis Koch](https://denniskoch.dev/).
