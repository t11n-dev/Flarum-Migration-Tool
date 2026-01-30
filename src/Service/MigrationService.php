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
            // Skip protected items and anything ending with _old
            if (in_array($item, $exclude) || str_ends_with($item, '_old')) continue;

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
            if (str_ends_with($sItem, '_old')) continue;
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

            // 2. Database Import
            $this->log("Step 2: Replacing database (Safety mode)...");
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

            // 3. File Replacement (Safety mode: rename to _old)
            $this->log("Step 3: Replacing files and folders (Safety: renaming existing items to _old)...");
            $this->replaceFilesFromBackup($extractDir);

            // 4. Update config.php
            $this->log("Step 4: Updating configuration...");
            $this->updateConfig($configData);

            // Final Cleanup of extraction temp
            $this->deleteDirectory($extractDir);
            $this->log("✓ MIGRATION SUCCESSFUL! Existing items renamed with _old suffix.");

            return [
                'success' => true,
                'message' => 'Migration completed successfully. Old files have been renamed to _old.'
            ];

        } catch (\Throwable $e) {
            $this->log("FATAL ERROR DURING MIGRATE: " . $e->getMessage(), "error");
            if (is_dir($extractDir)) $this->deleteDirectory($extractDir);
            throw $e;
        }
    }

    /**
     * Import database: clearing tables first
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
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        $this->log(" -> Clearing existing tables...");
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $this->log(" -> Executing SQL dump...");
        // Split by semicolon followed by newline to get individual statements
        $statements = explode(";\n", $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (\Exception $e) {
                    // Log but continue if possible
                    $this->log("SQL Exec warning: " . substr($e->getMessage(), 0, 100), "warning");
                }
            }
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        $this->log("Database imported successfully.");
    }

    /**
     * Rename existing files to _old and copy new ones
     */
    protected function replaceFilesFromBackup($extractDir)
    {
        $rootPath = base_path();
        
        // 1. Rename existing items in root to _old
        $this->log(" -> Backing up current root items to _old...");
        $protected = ['storage', 'config.php', 'vendor', '.git', 'node_modules', '.env', 'migration_temp', 'migration_upload'];
        $itemsInRoot = scandir($rootPath);
        
        foreach ($itemsInRoot as $item) {
            if (in_array($item, $protected) || $item === '.' || $item === '..') continue;
            if (str_ends_with($item, '_old')) continue;
            
            $oldPath = $rootPath . '/' . $item;
            $newPath = $rootPath . '/' . $item . '_old';
            
            if (file_exists($newPath)) {
                is_dir($newPath) ? $this->deleteDirectory($newPath) : @unlink($newPath);
            }
            
            @rename($oldPath, $newPath);
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
        
        // 3. Handle storage assets - safety first
        $this->log(" -> Updating assets (safety: renaming old to _old)...");
        $srcAssets = $extractDir . '/storage/assets';
        
        // Flarum assets can be in base/assets or public/assets
        $assetPaths = [public_path('assets'), base_path('assets')];
        
        foreach ($assetPaths as $dstAssets) {
            if (is_dir($srcAssets) && is_dir($dstAssets)) {
                $oldAssets = $dstAssets . '_old';
                if (is_dir($oldAssets)) $this->deleteDirectory($oldAssets);
                @rename($dstAssets, $oldAssets);
                $this->recursiveCopy($srcAssets, $dstAssets);
            }
        }
        
        // 4. Merge other storage data
        if (is_dir($extractDir . '/storage')) {
            $this->log(" -> Merging other storage directories...");
            $this->recursiveCopy($extractDir . '/storage', $rootPath . '/storage');
        }
    }

    /**
     * Delete all items ending in _old in root and assets
     */
    public function cleanupOldItems()
    {
        $rootPath = base_path();
        $items = scandir($rootPath);
        $count = 0;

        foreach ($items as $item) {
            if (str_ends_with($item, '_old')) {
                $p = $rootPath . '/' . $item;
                $this->log("Cleaning up: $item");
                is_dir($p) ? $this->deleteDirectory($p) : @unlink($p);
                $count++;
            }
        }

        // Also check assets_old
        $assetPaths = [public_path('assets_old'), base_path('assets_old')];
        foreach ($assetPaths as $p) {
            if (is_dir($p)) {
                $this->deleteDirectory($p);
                $count++;
            }
        }

        $this->log("✓ Cleanup finished. Total $count items removed.");
        return ['success' => true, 'removed_count' => $count];
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
     * Database Export Logic - Pure PHP
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
