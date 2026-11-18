# Changelog

All notable changes to `laravel-database-downloader` will be documented in this file.

## v3.4.1 - 2026-11-18

### Fixed

- `db:download -v` no longer hangs after extracting a large backup archive: verbose mode shows
  only the first 20 lines of a shell command's output plus a "… N more lines not shown" line.
  The full list (one line per file of the archive) is rendered by Termwind through libxml2,
  which takes quadratic time on libxml2 2.9.x (Ubuntu 24.04) — ~47 minutes for ~17k files.

## v3.4.0 - 2026-10-19

### Added

- `db:download` checks the age of dump files left by an earlier run: older than 24 hours
  (config `existing_files_max_age_hours`) they are deleted and downloaded fresh without asking.
- `--use-existing` imports those files regardless of their age, without asking.

### Changed behavior

- No more "The local download directory already exists. Delete it?" prompt: choosing a fresh
  download clears the directory directly.
- Existing dump files are offered as one choice ("Use existing files") instead of one entry per
  file — picking a single dump of a multi-dump backup imported an incomplete database.
- The target-database check (`CREATE DATABASE` / `USE`) also runs with anonymization off.
- A failed removal of the dump files makes the run fail while anonymization is on.
- Removed the unused config key `use_local_defaults_extra_file`.

### Fixed

- `--table` imported only the dump comments for dumps with comments (remote mysqldump); the
  filtered file no longer overwrites an input file named like the table.
- A left-behind backup archive is unpacked again instead of importing partly extracted dumps.
- `--import-from-local-file-path` with a spatie `.zip` now imports its `db-dumps/`; a `.zip` or
  `.sql.gz` is unpacked into the local download directory and the given file stays untouched.
- Import pipelines run with `pipefail`: an unreadable dump no longer ends as a successful import.
- `local_path` must lie strictly inside `database/` (no `..`, no sibling prefix) — the directory
  is deleted recursively. A missing trailing slash is added.
- Ctrl+C / SIGTERM run the same clean-up as a failed import (anonymize, delete dumps and the
  credentials file; needs `ext-pcntl`).
- The failure clean-up runs before logging, and a failing logger can no longer abort it.
- An existing `local_path` is also checked by its real path (no deleting through a symlink).
- Copies unpacked from an own `.zip` / `.sql.gz` are deleted like downloaded dumps.
- Unpacking a backup archive clears `db-dumps/` first: only that archive's dumps are imported.
- The DEFINER filter leaves `INSERT` lines alone, so stored texts are not rewritten.
- The credentials file uses MySQL option-file quoting (passwords with `'`, `\` or `"` work) and is
  created with mode 0600 from the start.
- `CREATE` / `DROP DATABASE` quote the name (names like `my-db` work); charset and collation are
  validated before `--dropExisting` drops anything.
- README: `--source` defaults to `backup` (no source prompt); `--files` only sets the event flag.

## v3.3.1 - 2026-10-13

### Fixed

- Anonymization: a value listing several addresses (e.g. a logged `Bcc: a@customer.com, b@your-company.com`)
  is no longer kept just because it ends in one of the `anonymize_keep_email_domains`.

## v3.3.0 - 2026-10-13

### Changed behavior

- `db:download` now overwrites contact data (email, phone, street address) in the imported
  database with placeholders by default — in every environment except `staging`. Opt out with
  `DB_DOWNLOADER_ANONYMIZE=false`. See "Anonymization" in the README for the config options
  (`anonymize_columns`, `anonymize_keep_email_domains`, `anonymize_keep_rows`).
- While anonymization is on, downloaded dump files are always deleted, also after a failed run.
- A dump with `CREATE DATABASE` / `USE` for another database than the import target is rejected.
