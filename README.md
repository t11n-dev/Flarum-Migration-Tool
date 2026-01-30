# Flarum Migration Tool - Complete Admin UI

A powerful Flarum extension that provides a full Admin UI for backing up, restoring, and migrating your forum to a new server with ease.

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![Flarum](https://img.shields.io/badge/flarum-%5E1.0-orange)
![License](https://img.shields.io/badge/license-MIT-green)

## ✨ Key Features

### 🛡️ Backup (Server A)
- **One-click Full Backup**: Generates a complete ZIP archive directly from the Admin Panel.
- **Smart Database Dump**: Uses system `mysqldump` for speed and reliability (no more PHP memory limit issues).
- **Flexible Options**: Choose to include/exclude uploads or vendor files.
- **Components Included**:
  - Database (`.sql` via mysqldump)
  - Settings (`.json`)
  - Config (`config.php`)
  - Composer dependencies (`composer.json`, `composer.lock`)
  - Assets (Avatars, uploads)
  - Extension Data
  - **Auto-Restore Script** (`migrate.sh`)

### 🚀 Migrate & Restore (Server B)
- **Intelligent Restoration**:
  - **Smart Config Patching**: Updates `config.php` database/url values while **preserving** your existing mail, queue, and driver settings.
  - **Permission Aware**: Automatically creates correct permissions (detects if running as Root vs Shared Host).
- **Two Ways to Restore**:
  1. **via Admin UI**: Upload the ZIP and let the extension handle everything.
  2. **via Terminal (Recommended)**: Use the generated `migrate.sh` script for a robust restoration on fresh servers.

## 📦 Installation

### 1. Install Extension

```bash
composer require nauhyuh/flarum-migration-tool
```

### 2. Enable Extension

```bash
php flarum extension:enable nauhyuh-migration-tool
```

## 📖 Usage Guide

### A. On the Old Server (Backup)

1.  Log in to the **Admin Panel**.
2.  Navigate to **Migration Tool**.
3.  Go to the **Backup** tab.
4.  Select options:
    *   ✅ **Include uploaded files** (Recommended)
    *   ⬜ **Include vendor** (Not recommended - makes the file huge; `composer install` is faster).
5.  Click **Create Backup**.
6.  Once finished, click **Download** to save the ZIP file.

### B. On the New Server (Restore)

#### Method 1: Using the Auto-Script (Best for VPS/Terminal)

Every backup ZIP comes with a smart `migrate.sh` script.

1.  Upload the ZIP file to your new server folder.
2.  Unzip it:
    ```bash
    unzip flarum_backup_202X-XX-XX.zip -d restore_temp
    ```
3.  Run the script:
    ```bash
    cd restore_temp
    chmod +x migrate.sh
    ./migrate.sh
    ```
4.  Follow the prompts. The script will:
    *   Import the database.
    *   Restore files.
    *   Patch `config.php` safely.
    *   Run `composer install`.
    *   Fix permissions automatically.

#### Method 2: Using Admin UI (If you already have a fresh Flarum)

1.  Install a fresh Flarum instance on the new server.
2.  Install this extension (`composer require nauhyuh/flarum-migration-tool`).
3.  Enable it and go to **Admin Panel** > **Migration Tool**.
4.  Switch to the **Migrate** tab.
5.  Upload your Backup ZIP.
6.  Fill in the new **Database Credentials** and **Site URL**.
7.  Click **Start Migration**.

## 🛠️ Metadata & Structure

The backup ZIP follows this structure:

```text
backup.zip
├── database.sql        # High-performance MySQL dump
├── settings.json       # Flarum settings table export
├── config.php          # Original config
├── composer.json       # Dependency map
├── metadata.json       # Server & Environment info
├── migrate.sh          # <---The magic restore script
├── assets/             # Public assets
└── storage/            # Internal storage files
```

## ⚠️ Security & Safety

*   **Sensitive Data**: Backups contain your **entire database** and **config secrets**. Store them securely.
*   **HTTPS**: Always use HTTPS when uploading/downloading backups via the Admin UI.
*   **Permissions**: The tool tries to be smart, but always ensure your `storage` folder is writable (`775`).

## ❓ Troubleshooting

**"mysqldump not found"**
The extension requires `mysqldump` to be installed on the server for high-performance backups. If you are on shared hosting without it, please contact your host or use the legacy PHP-based backup (available in older versions).

**"Permission denied"**
If `migrate.sh` fails with permission errors, try running it with `sudo` or check if the folder allows execution.

**"Upload failed" (File too large)**
Adjust your PHP settings:
```ini
upload_max_filesize = 512M
post_max_size = 512M
```

## 📄 License

MIT License.

---
Made with ❤️ for the Flarum community.
