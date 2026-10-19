# Changelog

All notable changes to `laravel-database-downloader` will be documented in this file.

## v3.4.0 - 2026-10-19

### Added

- `db:download` checks the age of dump files left by an earlier run: older than 24 hours
  (config `existing_files_max_age_hours`) they are deleted and downloaded fresh without asking.
- `--use-existing` imports those files regardless of their age, without asking.

### Changed behavior

- No more "The local download directory already exists. Delete it?" prompt: choosing a fresh
  download clears the directory directly.

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
