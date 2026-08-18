# Changelog

All notable changes to `padosoft/migrate-cloudflare-rules` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-18

### Added
- First release as a Laravel package (Laravel 12 and 13, PHP 8.2+).
- `cloudflare:migrate` Artisan command to migrate WAF custom rules, IP Access Rules (account and zone),
  User Agent Blocking rules, Rate Limiting rules, Custom Lists and Page Rules between two Cloudflare
  zones/accounts.
- Credentials are read from `config/migrate-cloudflare-rules.php` / `CLOUDFLARE_MIGRATE_*` environment variables.
- `--dryrun` and `--debug` options, duplicate detection on the destination, `--exclude` / `--only_rules_id` filters.
- Test suite based on Orchestra Testbench and `Http::fake()`.

[Unreleased]: https://github.com/padosoft/migrate-cloudflare-rules/compare/1.0.0...HEAD
[1.0.0]: https://github.com/padosoft/migrate-cloudflare-rules/releases/tag/1.0.0
