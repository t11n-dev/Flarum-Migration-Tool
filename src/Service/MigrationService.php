<?php

namespace Nauhyuh\MigrationTool\Service;

use Illuminate\Database\ConnectionInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Filesystem\Filesystem;
use ZipArchive;

class MigrationService
{
    protected $db;
    protected $settings;
    protected $files;
    protected $backupPath;
    protected $logPath;

    public function __construct()
    {
        $this->db = app('db.connection');
        $this->settings = app('flarum.settings');
        $this->files = new Filesystem();
        $this->backupPath = storage_path('backups');
        $this->logPath = storage_path('logs/migration.log');
        
        if (!is_dir($this->backupPath)) {
            @mkdir($this->backupPath, 0755, true);
        }

        if (!class_exists('ZipArchive')) {
            throw new \Exception("PHP 'zip' extension is not installed. ZIP functionality is required for backups.");
        }
    }

    /**
     * Create a full backup as ZIP
     */
    public function createBackup($includeUploads = true, $includeVendor = false, $includeConfig = false)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $this->log('Starting backup creation process...');
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "flarum_backup_{$timestamp}";
        $tempDir = storage_path('migration_temp/' . $backupName);
        
        try {
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $this->log("Working in temp directory: {$tempDir}");

            // 1. Export database
            $this->log('Step 1: Exporting database...');
            $this->exportDatabase($tempDir . '/database.sql');
            
            // 2. Export settings
            $this->log('Step 2: Exporting settings...');
            $this->exportSettings($tempDir . '/settings.json');
            
            // 3. Copy application files
            $this->log('Step 3: Copying application files...');
            $this->copySourceFiles($tempDir, $includeVendor, $includeConfig);

            // 4. Create internal metadata
            $this->log('Step 4: Creating metadata and scripts...');
            $this->createMetadata($tempDir, $includeUploads, $includeVendor);
            $this->createMigrationScript($tempDir);

            // 5. Pack everything
            $this->log('Step 5: Compressing to ZIP...');
            $zipPath = $this->backupPath . '/' . $backupName . '.zip';
            $this->createZip($tempDir, $zipPath);

            chmod($zipPath, 0664);

            $this->log('Step 6: Cleaning up temp files...');
            $this->deleteDirectory(storage_path('migration_temp'));
            
            $size = filesize($zipPath);
            $sizeFormatted = $this->formatBytes($size);
            $this->log("✓ SUCCESS! Backup created: {$backupName}.zip ({$sizeFormatted})");

            return [
                'success' => true,
                'filename' => $backupName . '.zip',
                'path' => $zipPath,
                'size' => $size,
                'size_formatted' => $sizeFormatted,
                'timestamp' => $timestamp
            ];

        } catch (\Throwable $e) {
            $this->log('FATAL ERROR: ' . $e->getMessage(), 'error');
            $this->log('Trace: ' . $e->getTraceAsString(), 'error');
            if (is_dir($tempDir)) {
                $this->deleteDirectory($tempDir);
            }
            throw $e;
        }
    }

    /**
     * Copy all files and folders from root, applying exclusions
     */
    protected function copySourceFiles($tempDir, $includeVendor, $includeConfig = false)
    {
        $rootPath = base_path();
        $items = scandir($rootPath);
        
        $exclude = ['current', 'storage', 'vendor', '.', '..', '.git', 'node_modules', 'migration_temp', 'migration_upload'];
        
        if ($includeVendor) {
            $exclude = array_diff($exclude, ['vendor']);
        }
        
        if (!$includeConfig) {
            $exclude[] = 'config.php';
        }

        foreach ($items as $item) {
            if (in_array($item, $exclude)) continue;

            $src = $rootPath . '/' . $item;
            $dst = $tempDir . '/' . $item;

            if (is_dir($src)) {
                $this->log(" -> Copying directory: {$item}");
                $this->recursiveCopy($src, $dst);
            } else {
                copy($src, $dst);
            }
        }

        // Special handling for storage: essential data only
        $this->log(" -> Copying essential storage data...");
        $storagePath = storage_path();
        $storageItems = array_diff(scandir($storagePath), ['.', '..', 'backups', 'logs', 'sessions', 'views', 'cache', 'migration_temp', 'migration_upload']);
        
        foreach ($storageItems as $sItem) {
            $src = $storagePath . '/' . $sItem;
            $dst = $tempDir . '/storage/' . $sItem;
            
            if (is_dir($src)) {
                $this->recursiveCopy($src, $dst);
            } else {
                @mkdir(dirname($dst), 0755, true);
                copy($src, $dst);
            }
        }
    }

    /**
     * Perform the actual migration/restore
     */
    public function performMigration($zipPath, $configData)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        if (!file_exists($zipPath)) {
            throw new \Exception("Backup file not found at: " . $zipPath);
        }

        $this->log("Starting migration process from: " . basename($zipPath));
        $extractDir = storage_path('migration_temp/' . uniqid('extract_'));

        try {
            // 1. Extract backup
            $this->log("Step 1: Extracting backup...");
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \Exception("Could not open ZIP file.");
            }
            mkdir($extractDir, 0755, true);
            $zip->extractTo($extractDir);
            $zip->close();

            // 2. Database Import (Replace old DB with new DB)
            $this->log("Step 2: Replacing database...");
            $sqlFile = $extractDir . '/database.sql';
            if (file_exists($sqlFile)) {
                $this->importDatabase(
                    $sqlFile, 
                    $configData['db_host'], 
                    $configData['db_name'], 
                    $configData['db_user'], 
                    $configData['db_pass']
                );
            }

            // 3. File Replacement (Total replacement except config.php)
            $this->log("Step 3: Replacing files and folders (Total Replacement)...");
            $this->replaceFilesFromBackup($extractDir);

            // 4. Update config.php
            $this->log("Step 4: Updating configuration...");
            $this->updateConfig($configData);

            // Final Cleanup
            $this->deleteDirectory($extractDir);
            $this->log("✓ MIGRATION SUCCESSFUL!");

            return [
                'success' => true,
                'message' => 'Migration completed successfully.'
            ];

        } catch (\Throwable $e) {
            $this->log("FATAL ERROR DURING MIGRATE: " . $e->getMessage(), "error");
            if (is_dir($extractDir)) $this->deleteDirectory($extractDir);
            throw $e;
        }
    }

    /**
     * Import database: drop tables first to "replace" the DB
     */
    protected function importDatabase($sqlFile, $host, $database, $username, $password)
    {
        $this->log("Importing database into {$database} @ {$host}...");
        
        $pdo = new \PDO("mysql:host={$host};dbname={$database}", $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);

        $sql = file_get_contents($sqlFile);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        $statements = explode(";\n", $sql);
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Drop existing tables to ensure "replacement"
        $this->log(" -> Clearing existing tables...");
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $this->log(" -> Executing SQL dump...");
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        $this->log("Database imported successfully.");
    }

    /**
     * Replace all files in root with backup files (Total Replacement except config.php)
     */
    protected function replaceFilesFromBackup($extractDir)
    {
        $rootPath = base_path();
        
        // 1. Delete everything in root EXCEPT protected items and config.php
        $this->log(" -> Cleaning up current directories...");
        $protected = ['storage', 'config.php', 'vendor', '.git', 'node_modules', '.env'];
        $itemsInRoot = scandir($rootPath);
        
        foreach ($itemsInRoot as $item) {
            if (in_array($item, $protected) || $item === '.' || $item === '..') continue;
            
            $p = $rootPath . '/' . $item;
            if (is_dir($p)) {
                $this->deleteDirectory($p);
            } else {
                @unlink($p);
            }
        }

        // 2. Copy items from backup to root
        $this->log(" -> Copying new files from backup...");
        $backupItems = array_diff(scandir($extractDir), ['.', '..', 'database.sql', 'settings.json', 'metadata.json', 'migrate.sh', 'config.php', 'storage']);

        foreach ($backupItems as $item) {
            $src = $extractDir . '/' . $item;
            $dst = $rootPath . '/' . $item;

            if (is_dir($src)) {
                $this->recursiveCopy($src, $dst);
            } else {
                copy($src, $dst);
            }
        }
        
        // 3. Special handling for storage/assets (replace)
        $this->log(" -> Replacing storage/assets...");
        $srcAssets = $extractDir . '/storage/assets';
        if (is_dir($srcAssets)) {
            $dstAssets = $rootPath . '/public/assets'; // Flarum web assets are often in public/assets or assets depending on setup
            // Compatibility: Flarum 1.x usually has assets in public/assets
            if (!is_dir($dstAssets)) $dstAssets = base_path('assets');
            
            if (is_dir($dstAssets)) $this->deleteDirectory($dstAssets);
            $this->recursiveCopy($srcAssets, $dstAssets);
        }
        
        // 4. Other storage data (extensions, etc)
        if (is_dir($extractDir . '/storage')) {
            $this->log(" -> Merging other storage data...");
            $this->recursiveCopy($extractDir . '/storage', $rootPath . '/storage');
        }
    }

    /**
     * Update existing config.php safely
     */
    public function updateConfig($data)
    {
        $configPath = base_path('config.php');
        if (!file_exists($configPath)) return;

        $config = include $configPath;
        
        if (!empty($data['db_host'])) $config['database']['host'] = $data['db_host'];
        if (!empty($data['db_name'])) $config['database']['database'] = $data['db_name'];
        if (!empty($data['db_user'])) $config['database']['username'] = $data['db_user'];
        if (!empty($data['db_pass'])) $config['database']['password'] = $data['db_pass'];
        if (!empty($data['new_url'])) $config['url'] = $data['new_url'];

        $content = "<?php return " . var_export($config, true) . ";";
        file_put_contents($configPath, $content);
        $this->log("Configuration updated in config.php");
    }

    /**
     * Database Export Logic - Pure PHP (Most compatible)
     */
    protected function exportDatabase($filePath)
    {
        $this->log("Starting native PHP database export...");
        
        try {
            $handle = fopen($filePath, 'w');
            if (!$handle) throw new \Exception("Cannot open file for writing: " . $filePath);

            fwrite($handle, "-- Flarum Native PHP Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $tables = $this->db->getPdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $tableName) {
                $createStmt = $this->db->getPdo()->query("SHOW CREATE TABLE `{$tableName}`")->fetch(\PDO::FETCH_ASSOC);
                $createSql = array_values($createStmt)[1];

                fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n" . $createSql . ";\n\n");

                $stmt = $this->db->getPdo()->query("SELECT * FROM `{$tableName}`");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $columns = array_keys($row);
                    $values = array_map(function($value) {
                        return $value === null ? 'NULL' : $this->db->getPdo()->quote($value);
                    }, array_values($row));

                    $sql = "INSERT INTO `{$tableName}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                    fwrite($handle, $sql);
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
            return true;
        } catch (\Throwable $e) {
            $this->log("PHP Export Error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    protected function exportSettings($filePath)
    {
        $settings = $this->db->table('settings')->get();
        $data = [];
        foreach ($settings as $s) $data[$s->key] = $s->value;
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function createMetadata($tempDir, $includeUploads, $includeVendor)
    {
        $metadata = [
            'app' => 'Flarum',
            'package' => 'nauhyuh/flarum-migration-tool',
            'date' => date('Y-m-d H:i:s'),
            'flarum_version' => \Flarum\Foundation\Application::VERSION,
            'php' => PHP_VERSION,
            'db_name' => $this->db->getDatabaseName(),
            'url' => app('flarum.config')['url'],
            'include_uploads' => $includeUploads,
            'include_vendor' => $includeVendor,
        ];
        file_put_contents($tempDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
    }

    protected function createMigrationScript($tempDir)
    {
        $script = "#!/bin/bash\necho \"Flarum Restore Script\"\n";
        file_put_contents($tempDir . '/migrate.sh', $script);
        chmod($tempDir . '/migrate.sh', 0755);
    }

    protected function createZip($sourceDir, $zipPath)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \Exception("Zip failed");
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $zip->addFile($file->getRealPath(), substr($file->getRealPath(), strlen($sourceDir) + 1));
            }
        }
        $zip->close();
    }

    public function listBackups()
    {
        $backups = [];
        if (!is_dir($this->backupPath)) return $backups;
        foreach (scandir($this->backupPath) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                $p = $this->backupPath . '/' . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => filesize($p),
                    'size_formatted' => $this->formatBytes(filesize($p)),
                    'created' => filemtime($p),
                    'created_formatted' => date('Y-m-d H:i:s', filemtime($p))
                ];
            }
        }
        usort($backups, fn($a, $b) => $b['created'] - $a['created']);
        return $backups;
    }

    public function deleteBackup($filename)
    {
        $filename = basename($filename);
        $p = $this->backupPath . '/' . $filename;
        if (!file_exists($p)) throw new \Exception("Backup file does not exist: $filename");
        if (!unlink($p)) throw new \Exception("Failed to delete backup file: $filename. Check file permissions.");
        $this->log("Deleted backup: $filename");
        return ['success' => true];
    }

    public function log($msg, $lvl = 'info')
    {
        file_put_contents($this->logPath, "[" . date('H:i:s') . "] [{$lvl}] {$msg}\n", FILE_APPEND);
    }

    public function getLogs($lines = 100)
    {
        if (!file_exists($this->logPath)) return [];
        return array_slice(file($this->logPath), -$lines);
    }

    public function recursiveCopy($src, $dst)
    {
        if (!is_dir($src)) return;
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            is_dir("$src/$f") ? $this->recursiveCopy("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
        }
    }

    public function deleteDirectory($dir)
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = "$dir/$f";
            is_dir($p) ? $this->deleteDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }

    protected function formatBytes($b, $p = 2)
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $b > 1024 && $i < count($u) - 1; $i++) $b /= 1024;
        return round($b, $p) . ' ' . $u[$i];
    }

    public function getCurrentConfig()
    {
        $config = app('flarum.config');
        return [
            'db_host' => $config['database']['host'] ?? 'localhost',
            'db_name' => $config['database']['database'] ?? '',
            'db_user' => $config['database']['username'] ?? '',
            'db_pass' => $config['database']['password'] ?? '',
            'url' => $config['url'] ?? '',
        ];
    }
}
