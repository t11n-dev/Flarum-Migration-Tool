# Flarum Migration Tool 🚀

A professional, safety-first migration and backup extension for Flarum. Transfer your forum between servers or create full-system backups with ease, all through a beautiful real-time Admin UI.

![Version](https://img.shields.io/badge/version-2.5.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Flarum](https://img.shields.io/badge/flarum-core%20v1.0+-orange.svg)

## ✨ Key Features

*   **1-Click Full Backup**: Zip your entire Flarum site, including database, settings, files and metadata (Flarum/PHP version, URL, table prefix).
*   **Safety-First Migration**: Files replaced by the backup are renamed with an `_old` suffix, and renamed items are automatically restored if the file copy fails. Nothing on disk is lost until you verify and click "Cleanup".
*   **Reliable Database Engine**: Pure PHP export/import, no `mysqldump` required.
    *   Streams the dump, so large forums don't run out of memory.
    *   Quote-aware SQL parser: post content containing `;`, `--` or `/* */` is restored exactly.
    *   Any failed SQL statement fails the migration instead of being silently skipped.
    *   Respects your table prefix: only the forum's own tables are exported and replaced, so other applications sharing the database are left untouched.
*   **Verified Completeness**:
    *   Each backup records the row count of every table; after import every table is checked and any difference fails the migration.
    *   The database is read from a consistent snapshot, so you can back up while the forum is live.
    *   The ZIP is verified right after creation and integrity-checked before a restore.
    *   Lossless export of emoji/UTF-8, BLOB and BIT columns; timestamps are kept in UTC across time zones; works with strict SQL mode.
    *   Symlinked folders (e.g. shared uploads) are included with their real content, and empty folders are kept.
*   **Post-Migration Checks**: The cache is cleared automatically, and you are warned if the destination runs a different Flarum version or lacks extensions that were enabled on the source forum.
*   **Real-time Progress Bar**: Track every step of your backup or migration with detailed status labels and percentage updates.
*   **Smart Pre-filling**: Automatically fills in your current server's URL and database host, name and user. The password is never sent to the browser; leave it empty to keep the current one.
*   **Flexible Options**:
    *   Include/Exclude user uploads (`assets/avatars`, `assets/files`). When restoring a backup without uploads, the destination's existing uploads are kept.
    *   Include/Exclude `vendor` directory.
    *   Include/Exclude `config.php` (sensitive credentials) in backups.
*   **Composer-Aware Restore**: If the backup has no `vendor`, your current `composer.json`/`composer.lock` are kept and the backup's versions are saved as `*.from_backup`, so installed packages always match.
*   **Concurrency Lock**: Only one backup or migration can run at a time.
*   **Post-Migration Cleanup**: Dedicated tool to permanently remove `_old` items once you've confirmed your new site is working perfectly.
*   **Logs Viewer**: Review the last 200 log lines of every backup and migration directly in the admin panel.
*   **Command Line Support**: Export, import and migrate from the terminal (see below).

## 🛠 Installation

Install the extension via Composer:

```bash
composer require t11n-dev/flarum-migration-tool
```

After installation, enable the extension in your Flarum Admin dashboard.

> **Upgrading from `nauhyuh/flarum-migration-tool`?** The package has moved to `t11n-dev/flarum-migration-tool`. Switch with:
>
> ```bash
> composer remove nauhyuh/flarum-migration-tool
> composer require t11n-dev/flarum-migration-tool
> php flarum cache:clear
> ```
>
> Then enable **Migration Tool** again in the admin dashboard. Existing backups in `storage/backups` are kept.

## 🚀 How to Migrate

### Step 1: Create a Backup on Source Server
1. Go to **Admin > Migration Tool > Backup** tab.
2. Select your desired options (exclude `vendor` for smaller files).
3. Click **Create Backup**. Watch the real-time progress bar.
4. Once finished, click **Download** to save the ZIP to your computer.

### Step 2: Restore on Destination Server
1. Install Flarum and this extension on your new server.
2. Go to **Admin > Migration Tool > Migrate** tab.
3. Upload your ZIP backup file.
4. Check the pre-filled URL and database details. Update them if you are migrating to a different database; leave the password empty to keep the current one.
5. Click **Start Migration**. The tool will:
    *   Extract the backup.
    *   Import the database (tables contained in the backup are replaced).
    *   Rename the files and folders the backup replaces to `*_old`, then copy in the backup's files.
    *   Verify that every table has the same number of rows as the source.
    *   Update `config.php` with the new URL, database credentials and the source forum's table prefix.
    *   Clear the cache and show warnings about version or extension differences.
6. Check your site and the **Logs** tab. If everything is perfect, click **Cleanup Old Files** to delete the `_old` items.

> **Tip:** Uploads stored on external services (e.g. S3 via FoF Upload) are not part of the backup; they stay where they are. If the source forum used extensions that are not installed on the destination and the backup excludes `vendor`, run `composer install` based on `composer.json.from_backup`.

## 💻 Command Line

```bash
# Create a backup in storage/backups
php flarum migration:export [--include-uploads] [--include-vendor]

# Import database and assets into the current site, keeping its config
php flarum migration:import /path/to/backup.zip [--force]

# Full interactive migration (asks for database credentials and new URL)
php flarum migration:migrate /path/to/backup.zip
```

The CLI is recommended for large forums that hit PHP time or upload size limits in the browser.

## 📋 Requirements

*   Flarum v1.0+
*   PHP 8.0+ with the `zip` extension enabled.
*   Write permissions for `storage` and root directories.
*   MySQL/MariaDB database.

## 🛡 Security & Safety

*   **Admin Only**: All migration routes are strictly protected by Flarum's admin authorization.
*   **No Credential Leaks**: The database password is never exposed to the admin UI, and API errors don't include stack traces.
*   **Safe File Handling**: Filenames are sanitized against path traversal; symlinks are recreated instead of followed.
*   **Recoverable Files**: Replaced files are kept as `_old` until you choose to delete them. Note that the database tables contained in the backup are replaced, so always keep a backup of the destination first.
*   **Protect Your Backups**: Backups contain your full database (and optionally `config.php`). Make sure your web server denies public access to the `storage` directory, and delete backups you no longer need.

## 📄 License

This project is licensed under the MIT License.

---
**Crafted with ❤️ by Nauhyuh**
