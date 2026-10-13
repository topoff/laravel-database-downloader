# Team Memory: laravel-database-downloader

This file contains deliberate, team-reviewed memory for this project.
It is versioned in this repository and is intended for Claude Code and Codex.

## Stable facts

- `db:download` anonymizes by default (since v3.3.0): after the import and before `DatabaseImported`
  it overwrites email / phone / street-address columns with placeholders. Off only in the `staging`
  environment or via `DB_DOWNLOADER_ANONYMIZE=false` — the staging exception lives in the package
  default AND in `DownloadDatabaseCommand::shouldAnonymize()` (stale config cache), keep both in sync.
- Columns are found by name (`src/Support/AnonymizationSqlBuilder.php`); a host adds columns the
  name cannot reveal (e.g. a log table's `to`) via `anonymize_columns`. A new contact column with an
  unusual name is NOT covered until the host lists it.
- New config keys must stay top-level: hosts have published configs, and `mergeConfigFrom` only
  fills missing top-level keys.

## Architecture pointers

- TODO: Add links to relevant docs, code paths, or decisions.
- Example: Authentication → `docs/authentication.md`
- Example: Architecture decisions → `docs/decisions/`

## Commands and workflows

- Gates: `composer gates`. The anonymization tests against a real MySQL only run with
  `DB_DOWNLOADER_TEST_DSN="mysql:host=127.0.0.1;port=3306" DB_DOWNLOADER_TEST_USER=root` set
  (skipped otherwise, also in CI) — set it locally whenever `AnonymizationSqlBuilder` changes.

## Conventions specific to this project

- TODO: Add project-specific conventions that agents should know before editing code.

## Do not store here

- Personal preferences.
- Temporary debugging notes.
- Secrets, credentials, API keys, tokens, or private customer data.
- Facts that belong only to another project.
- Long-form agent documentation that belongs in `docs/`.
