<?php

namespace Nauhyuh\MigrationTool\Service;

use Illuminate\Database\ConnectionInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Filesystem\Filesystem;
use Flarum\Foundation\Config;
use ZipArchive;

class MigrationService
{
    protected $settings;
    protected $files;
    protected $backupPath;
    protected $logPath;

    public function __construct(
        ConnectionInterface $db,
        SettingsRepositoryInterface $settings,
        Filesystem $files
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->files = $files;
        $this->backupPath = storage_path('backups');
        $this->logPath = storage_path('logs/migration.log');
        
        // Tạo thư mục backups nếu chưa có
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * Tạo backup đầy đủ dạng ZIP
     */
    public function createBackup($includeUploads = true, $includeVendor = false)
    {
        $this->log('Starting backup creation...');
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "flarum_backup_{$timestamp}";
        $tempDir = sys_get_temp_dir() . '/' . $backupName;
        
        try {
            // Tạo thư mục tạm
            mkdir($tempDir, 0755, true);
            $this->log("Created temp directory: {$tempDir}");

            // Export database
            $this->log('Exporting database...');
            $this->exportDatabase($tempDir . '/database.sql');
            
            // Export settings
            $this->log('Exporting settings...');
            $this->exportSettings($tempDir . '/settings.json');
            
            // Copy config.php
            $this->log('Copying config...');
            $this->copyConfig($tempDir);
            
            // Copy composer files
            $this->log('Copying composer files...');
            $this->copyComposerFiles($tempDir);
            
            // Copy .env if exists
            if (file_exists(base_path('.env'))) {
                copy(base_path('.env'), $tempDir . '/.env');
                $this->log('Copied .env file');
            }

            // Copy uploads
            if ($includeUploads) {
                $this->log('Copying uploads...');
                $this->copyUploads($tempDir);
            }

            // Copy vendor nếu được yêu cầu
            if ($includeVendor) {
                $this->log('Copying vendor directory...');
                $this->copyVendor($tempDir);
            }

            // Copy extensions config
            $this->log('Copying extensions data...');
            $this->copyExtensionsData($tempDir);

            // Tạo metadata
            $this->log('Creating metadata...');
            $this->createMetadata($tempDir, $includeUploads, $includeVendor);

            // Tạo migration script
            $this->log('Creating migration script...');
            $this->createMigrationScript($tempDir);

            // Nén thành ZIP
            $this->log('Creating ZIP archive...');
            $zipPath = $this->backupPath . '/' . $backupName . '.zip';
            $this->createZip($tempDir, $zipPath);

            // Xóa thư mục tạm
            $this->deleteDirectory($tempDir);
            
            $size = filesize($zipPath);
            $sizeFormatted = $this->formatBytes($size);
            $this->log("Backup completed successfully! File: {$backupName}.zip ({$sizeFormatted})");

            return [
                'success' => true,
                'filename' => $backupName . '.zip',
                'path' => $zipPath,
                'size' => $size,
                'size_formatted' => $sizeFormatted,
                'timestamp' => $timestamp
            ];

        } catch (\Exception $e) {
            $this->log('Error: ' . $e->getMessage(), 'error');
            
            // Cleanup
            if (is_dir($tempDir)) {
                $this->deleteDirectory($tempDir);
            }
            
            throw $e;
        }
    }

    /**
     * Export database to SQL file
     */
    /**
     * Export database using mysqldump for performance
     */
    protected function exportDatabase($filePath)
    {
        $config = resolve('flarum.config');
        $conf = $config['database'];
        
        // Security: Create a temporary config file to store credentials
        // avoiding password exposure in process list (ps aux)
        $cnfFile = sys_get_temp_dir() . '/flarum_dump_' . uniqid() . '.cnf';
        $cnfContent = "[client]\n" .
                      "user=\"" . addslashes($conf['username']) . "\"\n" .
                      "password=\"" . addslashes($conf['password']) . "\"\n" .
                      "host=\"" . addslashes($conf['host']) . "\"\n" .
                      "port=\"" . addslashes($conf['port'] ?? 3306) . "\"";
        
        file_put_contents($cnfFile, $cnfContent);
        chmod($cnfFile, 0600); // Restricted permissions

        try {
            // Build mysqldump command
            // --defaults-extra-file: Read creds from file
            // --single-transaction: Ensure consistent dump without locking InnoDB tables
            // --quick: Retrieve rows row-by-row
            // --no-tablespaces: Fix specific MySQL 8.x permission issue
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --no-tablespaces %s > %s',
                escapeshellarg($cnfFile),
                escapeshellarg($conf['database']),
                escapeshellarg($filePath)
            );
            
            $output = [];
            $returnVar = 0;
            exec($cmd . ' 2>&1', $output, $returnVar);
            
            if ($returnVar !== 0) {
                // Check if mysqldump is missing
                $errorMsg = implode("\n", $output);
                if (strpos($errorMsg, 'command not found') !== false) {
                    throw new \Exception("mysqldump binary not found. Please stick to PHP-based backup or install MySQL client tools.");
                }
                throw new \Exception('mysqldump failed: ' . $errorMsg);
            }
            
            // Append header manually if needed (optional, mostly for consistency verification)
            // But mysqldump headers are sufficient.

        } finally {
            // Always clean up credentials file
            if (file_exists($cnfFile)) {
                @unlink($cnfFile);
            }
        }
    }

    /**
     * Export settings
     */
    protected function exportSettings($filePath)
    {
        $settings = $this->db->table('settings')->get();
        $settingsArray = [];

        foreach ($settings as $setting) {
            $settingsArray[$setting->key] = $setting->value;
        }

        file_put_contents($filePath, json_encode($settingsArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Copy config.php
     */
    protected function copyConfig($tempDir)
    {
        $configPath = base_path('config.php');
        if (file_exists($configPath)) {
            copy($configPath, $tempDir . '/config.php');
        }
    }

    /**
     * Copy composer files
     */
    protected function copyComposerFiles($tempDir)
    {
        $files = ['composer.json', 'composer.lock'];
        foreach ($files as $file) {
            $path = base_path($file);
            if (file_exists($path)) {
                copy($path, $tempDir . '/' . $file);
            }
        }
    }

    /**
     * Copy uploads
     */
    protected function copyUploads($tempDir)
    {
        $uploadsPath = public_path('assets');
        $destPath = $tempDir . '/assets';
        
        if (is_dir($uploadsPath)) {
            $this->recursiveCopy($uploadsPath, $destPath);
        }
    }

    /**
     * Copy vendor directory
     */
    protected function copyVendor($tempDir)
    {
        $vendorPath = base_path('vendor');
        $destPath = $tempDir . '/vendor';
        
        if (is_dir($vendorPath)) {
            $this->recursiveCopy($vendorPath, $destPath);
        }
    }

    /**
     * Copy extensions data
     */
    protected function copyExtensionsData($tempDir)
    {
        $extensionsPath = base_path('storage/extensions');
        $destPath = $tempDir . '/storage/extensions';
        
        if (is_dir($extensionsPath)) {
            $this->recursiveCopy($extensionsPath, $destPath);
        }
    }

    /**
     * Create metadata
     */
    protected function createMetadata($tempDir, $includeUploads, $includeVendor)
    {
        $metadata = [
            'version' => '2.0',
            'backup_date' => date('Y-m-d H:i:s'),
            'flarum_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->db->selectOne('SELECT VERSION() as version')->version,
            'include_uploads' => $includeUploads,
            'include_vendor' => $includeVendor,
            'database_name' => $this->db->getDatabaseName(),
            'url' => url('/'),
            'extensions' => $this->getInstalledExtensions(),
            'server_info' => [
                'os' => PHP_OS,
                'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
            ]
        ];

        file_put_contents($tempDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Get installed extensions
     */
    protected function getInstalledExtensions()
    {
        $extensions = [];
        
        // Get from database
        $enabledExtensions = $this->db->table('extensions')->pluck('id')->toArray();
        
        // Get from composer.json
        $composerPath = base_path('composer.json');
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            foreach ($composer['require'] ?? [] as $package => $version) {
                if (strpos($package, 'flarum') !== false || in_array($package, $enabledExtensions)) {
                    $extensions[] = [
                        'name' => $package,
                        'version' => $version,
                        'enabled' => in_array($package, $enabledExtensions)
                    ];
                }
            }
        }

        return $extensions;
    }

    /**
     * Create migration script
     */
    /**
     * Create migration script
     * Generates a robust bash script for auto-restore on the new server
     */
    protected function createMigrationScript($tempDir)
    {
        // Embed a PHP helper script to safely update config array
        // This ensures we keep MAIL, QUEUE, and specialized settings from the old site
        $phpConfigUpdater = <<<'PHP'
<?php
// config_updater.php - Helper to update Flarum config safely
$configFile = 'config.php';
if (!file_exists($configFile)) { 
    echo "Error: config.php not found\n"; 
    exit(1); 
}

$config = include $configFile;
$updates = json_decode(getenv('FLARUM_CONFIG_UPDATES'), true);

if (!$updates) {
    echo "Error: No updates provided\n";
    exit(1);
}

// Deep merge for database config to preserve options like 'engine' or 'sslmode'
if (isset($updates['database'])) {
    $config['database'] = array_merge($config['database'], $updates['database']);
    unset($updates['database']);
}

// Update other top-level keys (url, debug, etc)
foreach ($updates as $key => $value) {
    $config[$key] = $value;
}

$content = "<?php return " . var_export($config, true) . ";";
file_put_contents($configFile, $content);
echo "Config updated successfully.\n";
PHP;

        // Escape the PHP code to be embedded in HEREDOC
        $phpConfigUpdater = str_replace('$', '\$', $phpConfigUpdater);

        $script = <<<BASH
#!/bin/bash

# Flarum Auto Migration Script (Smart Restore)
# Generated by Migration Tool
# 
# This script is designed to be:
# 1. Non-destructive: It patches config instead of overwriting.
# 2. Environment-aware: Adapts permissions based on user (Root vs Shared Host).

set -e

# Colors
RED='\\033[0;31m'
GREEN='\\033[0;32m'
YELLOW='\\033[1;33m'
BLUE='\\033[0;34m'
NC='\\033[0m'

echo -e "\${BLUE}======================================\${NC}"
echo -e "\${BLUE}   Flarum Intelligent Migration Tool \${NC}"
echo -e "\${BLUE}======================================\${NC}"
echo ""

SCRIPT_DIR="\$( cd "\$( dirname "\${BASH_SOURCE[0]}" )" && pwd )"
CURRENT_USER=\$(whoami)

echo -e "\${YELLOW}[Check] Running as user: \${CURRENT_USER}\${NC}"

# 1. Validation
if [ ! -f "\${SCRIPT_DIR}/config.php" ]; then
    echo -e "\${RED}[Error] config.php is missing in the backup package!\${NC}"
    exit 1
fi

# 2. Extract & Restore Files
echo -e "\${YELLOW}[1/6] Restoring files...\${NC}"

# Restore assets
if [ -d "\${SCRIPT_DIR}/assets" ]; then
    echo " -> Restoring public/assets..."
    mkdir -p public/assets
    cp -r "\${SCRIPT_DIR}/assets/"* public/assets/
fi

# Restore storage
if [ -d "\${SCRIPT_DIR}/storage" ]; then
    echo " -> Restoring storage..."
    mkdir -p storage
    cp -r "\${SCRIPT_DIR}/storage/"* storage/
fi

# Restore Composer files (crucial for dependencies)
if [ -f "\${SCRIPT_DIR}/composer.json" ]; then
    echo " -> Restoring composer.json..."
    cp "\${SCRIPT_DIR}/composer.json" ./
    cp "\${SCRIPT_DIR}/composer.lock" ./
fi

# Restore base Config (temporarily)
cp "\${SCRIPT_DIR}/config.php" ./config.php
echo -e "\${GREEN}✓ Files restored.\${NC}"

# 3. Database Import
echo -e "\\n\${YELLOW}[2/6] Database Import\${NC}"
read -p "MySQL Host [localhost]: " DB_HOST
DB_HOST=\${DB_HOST:-localhost}
read -p "MySQL Database Name: " DB_NAME
read -p "MySQL User: " DB_USER
read -sp "MySQL Password: " DB_PASS
echo ""

# Check if mysql client exists
if ! command -v mysql &> /dev/null; then
    echo -e "\${RED}MySQL client is not installed. Cannot import database automatically.\${NC}"
    echo "Please retry manually using phpMyAdmin or similar tools."
    exit 1
fi

echo " -> Importing database (this may take a while)..."
mysql -h "\$DB_HOST" -u "\$DB_USER" -p"\$DB_PASS" "\$DB_NAME" < "\${SCRIPT_DIR}/database.sql"
if [ $? -eq 0 ]; then
    echo -e "\${GREEN}✓ Database imported successfully.\${NC}"
else
    echo -e "\${RED}✗ Database import failed.\${NC}"
    exit 1
fi

# 4. Update Configuration (Smart Patching)
echo -e "\\n\${YELLOW}[3/6] Updating Configuration\${NC}"
read -p "New Site URL (e.g., https://domain.com): " SITE_URL

# Create the PHP helper
cat > .config_updater.php <<'PHP_Script'
$phpConfigUpdater
PHP_Script

# Prepare JSON payload for the updater
export FLARUM_CONFIG_UPDATES=\$(cat <<JSON
{
    "url": "\$SITE_URL",
    "database": {
        "host": "\$DB_HOST",
        "database": "\$DB_NAME",
        "username": "\$DB_USER",
        "password": "\$DB_PASS"
    }
}
JSON
)

# Run the PHP helper
php .config_updater.php
rm .config_updater.php

echo -e "\${GREEN}✓ Config patched (Mail & other settings preserved).\${NC}"

# 5. Composer Dependencies
echo -e "\\n\${YELLOW}[4/6] Installing Dependencies\${NC}"
if command -v composer &> /dev/null; then
    composer install --no-dev --optimize-autoloader --no-scripts
    echo -e "\${GREEN}✓ Composer dependencies installed.\${NC}"
else
    echo -e "\${RED}Composer command not found. Skipping install.\${NC}"
    echo "Please run 'composer install' manually."
fi

# 6. Permissions (Smart Mode)
echo -e "\\n\${YELLOW}[5/6] Setting Permissions\${NC}"

# Standard chmod (always safe for owner)
chmod -R 775 storage
chmod -R 775 public/assets

# Only attempt chown if we are root
if [ "\$EUID" -eq 0 ]; then
    echo " -> Running as root, forcing ownership to www-data..."
    chown -R www-data:www-data storage
    chown -R www-data:www-data public/assets
    chown -R www-data:www-data config.php
else
    echo " -> Running as non-root user. Assuming current user is the web user."
    echo " -> Skipping 'chown' to avoid permission errors."
fi
echo -e "\${GREEN}✓ Permissions set.\${NC}"

# 7. Final Clean & Cache Clear
echo -e "\\n\${YELLOW}[6/6] Finalizing...\${NC}"
php flarum cache:clear
php flarum migrate

echo -e "\\n\${GREEN}==========================================\${NC}"
echo -e "\${GREEN}   MIGRATION SUCCESSFUL! \${NC}"
echo -e "\${GREEN}==========================================\${NC}"
echo "Your site is ready at: \$SITE_URL"
echo ""

BASH;
        
        // Write script and make executable
        file_put_contents($tempDir . '/migrate.sh', $script);
        chmod($tempDir . '/migrate.sh', 0755);
    }

    /**
     * Create ZIP archive
     */
    protected function createZip($sourceDir, $zipPath)
    {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Cannot create ZIP file: {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
    }

    /**
     * List all backups
     */
    public function listBackups()
    {
        $backups = [];
        
        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        $files = scandir($this->backupPath);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $filePath = $this->backupPath . '/' . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => filesize($filePath),
                    'size_formatted' => $this->formatBytes(filesize($filePath)),
                    'created' => filemtime($filePath),
                    'created_formatted' => date('Y-m-d H:i:s', filemtime($filePath))
                ];
            }
        }

        // Sort by date descending
        usort($backups, function($a, $b) {
            return $b['created'] - $a['created'];
        });

        return $backups;
    }

    /**
     * Delete a backup
     */
    public function deleteBackup($filename)
    {
        $filePath = $this->backupPath . '/' . $filename;
        
        if (!file_exists($filePath)) {
            throw new \Exception('Backup file not found');
        }

        unlink($filePath);
        $this->log("Deleted backup: {$filename}");
        
        return ['success' => true];
    }

    /**
     * Logging
     */
    public function log($message, $level = 'info')
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        file_put_contents($this->logPath, $logMessage, FILE_APPEND);
    }

    /**
     * Get logs
     */
    public function getLogs($lines = 100)
    {
        if (!file_exists($this->logPath)) {
            return [];
        }

        $content = file($this->logPath);
        return array_slice($content, -$lines);
    }

    /**
     * Utility functions
     */
    protected function recursiveCopy($src, $dst)
    {
        if (!is_dir($src)) return;
        
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;
                
                if (is_dir($srcPath)) {
                    $this->recursiveCopy($srcPath, $dstPath);
                } else {
                    copy($srcPath, $dstPath);
                }
            }
        }
        
        closedir($dir);
    }

    protected function deleteDirectory($dir)
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
