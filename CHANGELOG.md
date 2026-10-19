# Changelog

All notable changes to this project will be documented in this file.

## [2.5.1] - 2026-10-19

### Changed
- The package moved to `t11n-dev/flarum-migration-tool` (repository: https://github.com/t11n-dev/Flarum-Migration-Tool). The extension ID is now `t11n-dev-migration-tool`; re-enable the extension after switching.

### Added
- Data completeness checks: the backup records the row count of every table and the import verifies them; any difference fails the migration.
- Backups are read from a consistent snapshot, so data stays coherent while the forum is in use.
- Every new backup ZIP is verified after creation; uploaded backups are integrity-checked before extraction.
- After migration the cache is cleared automatically, and warnings are shown for a different Flarum version or for extensions enabled on the source forum but not installed on the destination.

### Fixed
- Database import no longer corrupts data: the SQL dump is streamed and split with a quote-aware parser, so post content containing `;`, `--` or `/* */` is preserved. Failed statements now fail the migration instead of being logged as warnings.
- Import no longer drops every table in the target database; only tables contained in the dump are replaced.
- Export only includes tables with the forum's table prefix, skips views, and streams rows to avoid running out of memory. The prefix is restored into `config.php` on migration.
- Restore only renames items that the backup replaces, keeps the current `composer.json`/`composer.lock` when the backup has no `vendor` (saved as `*.from_backup`), and rolls back renamed items if copying fails.
- "Include uploaded files" now actually excludes `assets/avatars` and `assets/files`; restoring such a backup keeps the existing uploads.
- `migration:migrate` and `migration:import` crashed (missing console helpers, protected method call).
- A missing `zip` extension no longer breaks every `php flarum` command.
- TIMESTAMP values are exported and imported in UTC, so they are not shifted between servers in different time zones.
- Import works on servers with strict SQL mode (zero dates) and keeps rows with id 0.
- Binary (BLOB) and BIT columns are exported losslessly.
- Empty directories (e.g. an empty uploads folder) are kept in the backup.
- Symlinked files and directories are included in backups with their real content (with loop protection); restores recreate symlinks.
- Backups and migrations can no longer run concurrently.

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
