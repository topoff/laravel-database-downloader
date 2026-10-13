# Changelog

All notable changes to `laravel-database-downloader` will be documented in this file.

## v3.3.0 - 2026-10-13

### Changed behavior

- `db:download` now overwrites contact data (email, phone, street address) in the imported
  database with placeholders by default — in every environment except `staging`. Opt out with
  `DB_DOWNLOADER_ANONYMIZE=false`. See "Anonymization" in the README for the config options
  (`anonymize_columns`, `anonymize_keep_email_domains`, `anonymize_keep_rows`).
- While anonymization is on, downloaded dump files are always deleted, also after a failed run.
- A dump with `CREATE DATABASE` / `USE` for another database than the import target is rejected.
