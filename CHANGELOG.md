# Changelog

All notable changes to this project will be documented in this file.

## [2.5.0] - 2026-10-19

### Fixed
- Database import no longer corrupts data: the SQL dump is streamed and split with a quote-aware parser, so post content containing `;`, `--` or `/* */` is preserved. Failed statements now fail the migration instead of being logged as warnings.
- Import no longer drops every table in the target database; only tables contained in the dump are replaced.
- Export only includes tables with the forum's table prefix, skips views, and streams rows to avoid running out of memory. The prefix is restored into `config.php` on migration.
- Restore only renames items that the backup replaces, keeps the current `composer.json`/`composer.lock` when the backup has no `vendor` (saved as `*.from_backup`), and rolls back renamed items if copying fails.
- "Include uploaded files" now actually excludes `assets/avatars` and `assets/files`; restoring such a backup keeps the existing uploads.
- `migration:migrate` and `migration:import` crashed (missing console helpers, protected method call).
- A missing `zip` extension no longer breaks every `php flarum` command.
- Backups and migrations can no longer run concurrently; symlinks are recreated instead of followed.

### Security
- The database password is no longer sent to the admin UI; leaving the password field empty keeps the current one.
- Stack traces are no longer included in API error responses.
- The migrate endpoint validates the uploaded filename.
- Removed the unused `/migration/config/update` endpoint.

## [2.0.0] - 2026-01-30

### Added
- Complete admin UI with tabs (Backup, Migrate, Logs)
- Tab Backup:
  - Create backup with options (uploads, vendor)
  - List all backups with size and date
  - Download backups directly
  - Delete backups
- Tab Migrate:
  - Upload backup file via UI
  - Configure database and URL in admin
  - Automatic migration process
  - Real-time progress tracking
- Tab Logs:
  - View migration logs in real-time
  - Refresh logs button
- Auto-generated migration script in backup
- Metadata tracking with server info
- Support for .env file backup
- Vietnamese and English translations
- Comprehensive logging system

### Changed
- Improved backup structure with better organization
- Enhanced error handling and reporting
- Better file organization and code structure

### Fixed
- Permission issues during migration
- Database import with foreign keys
- Config.php format preservation

## [1.0.0] - 2026-01-29

### Added
- Initial release
- Basic CLI commands (export, import)
- Database export/import
- File copying functionality
- Basic backup creation
