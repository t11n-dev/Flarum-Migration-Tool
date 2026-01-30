# Changelog

All notable changes to this project will be documented in this file.

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
