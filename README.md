# Flarum Migration Tool 🚀

A professional, safety-first migration and backup extension for Flarum. Transfer your forum between servers or create full-system backups with ease, all through a beautiful real-time Admin UI.

![Version](https://img.shields.io/badge/version-2.5.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Flarum](https://img.shields.io/badge/flarum-core%20v1.0+-orange.svg)

## ✨ Key Features

*   **1-Click Full Backup**: Zip your entire Flarum site, including database, settings, and files.
*   **Safety-First Migration**: Existing server files are renamed with an `_old` suffix during migration. Data is never lost until you verify and click "Cleanup".
*   **Real-time Progress Bar**: Track every step of your backup or migration with detailed status labels and percentage updates.
*   **Smart Pre-filling**: Automatically detects your current server's URL and database credentials to save time during migrations.
*   **Flexible Options**:
    *   Include/Exclude `vendor` directory.
    *   Include/Exclude user uploads (avatars, attachments).
    *   Include/Exclude `config.php` (sensitive credentials) in backups.
*   **Robust DB Engine**: Pure PHP database export/import engine designed for maximum compatibility across different hosting environments.
*   **Post-Migration Cleanup**: Dedicated tool to permanently remove old files once you've confirmed your new site is working perfectly.
*   **Command Line Support**: Advanced users can still perform migrations via `php flarum migration:migrate`.

## 🛠 Installation

Install the extension via Composer:

```bash
composer require nauhyuh/flarum-migration-tool
```

After installation, enable the extension in your Flarum Admin dashboard.

## 🚀 How to Migrate

### Step 1: Create a Backup on Source Server
1. Go to **Admin > Migration Tool > Backup** tab.
2. Select your desired options (Exclude `vendor` for smaller files).
3. Click **Create Backup**. Watch the real-time progress bar.
4. Once finished, click **Download** to save the ZIP to your computer.

### Step 2: Restore on Destination Server
1. Install this extension on your new Flarum site.
2. Go to **Admin > Migration Tool > Migrate** tab.
3. Upload your ZIP backup file.
4. The tool will auto-fill your current server's DB details. Update if you are migrating to a different database.
5. Click **Start Migration**. The tool will:
    *   Backup existing files to `*_old`.
    *   Replace the database.
    *   Restore your files.
6. Check your site. If everything is perfect, click **Cleanup Old Files** to delete the `_old` items.

## 📋 Requirements

*   Flarum v1.0+
*   PHP `zip` extension enabled.
*   Write permissions for `storage` and root directories.
*   MySQL/MariaDB database.

## 🛡 Security & Safety

*   **No Data Deletion**: Migrations use high-level safety renaming. Your old files are kept safe as `_old` until you choose to delete them.
*   **Cleanup Tool**: Ensures no sensitive `_old` files (like `config.php_old`) are left on your server publicly.
*   **Admin Only**: All migration routes are strictly protected by Flarum's admin authorization.

## 📄 License

This project is licensed under the MIT License.

---
**Crafted with ❤️ by Nauhyuh**
