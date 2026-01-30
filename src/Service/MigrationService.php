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
    public function createBackup($includeUploads = true, $includeVendor = false)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $this->log('Starting backup creation process...');
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "flarum_backup_{$timestamp}";
        // Use a temp dir inside storage to ensure write permissions
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
            
            // 3. Copy application files (All folders except specified exclusions)
            $this->log('Step 3: Copying application files...');
            $this->copySourceFiles($tempDir, $includeVendor);

            // 4. Create internal metadata
            $this->log('Step 4: Creating metadata and scripts...');
            $this->createMetadata($tempDir, $includeUploads, $includeVendor);
            $this->createMigrationScript($tempDir);

            // 5. Pack everything
            $this->log('Step 5: Compressing to ZIP...');
            $zipPath = $this->backupPath . '/' . $backupName . '.zip';
            $this->createZip($tempDir, $zipPath);

            // Set permissions for the zip file
            chmod($zipPath, 0664);

            // Cleanup
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
    protected function copySourceFiles($tempDir, $includeVendor)
    {
        $rootPath = base_path();
        $items = scandir($rootPath);
        
        $exclude = ['current', 'storage', 'vendor', '.', '..', '.git', 'node_modules', 'migration_temp'];
        if ($includeVendor) {
            $exclude = array_diff($exclude, ['vendor']);
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

        // Special handling for storage: only copy non-temp/non-backup data
        $this->log(" -> Copying essential storage data...");
        $storagePath = storage_path();
        $storageItems = array_diff(scandir($storagePath), ['.', '..', 'backups', 'logs', 'sessions', 'views', 'cache', 'migration_temp']);
        
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
     * Database Export Logic - Pure PHP (Most compatible)
     */
    protected function exportDatabase($filePath)
    {
        $this->log("Starting native PHP database export...");
        
        try {
            $handle = fopen($filePath, 'w');
            if (!$handle) throw new \Exception("Cannot open file for writing: " . $filePath);

            fwrite($handle, "-- Flarum Native PHP Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $dbName = $this->db->getDatabaseName();
            $tables = $this->db->getPdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $tableName) {
                // Get Create Table
                $createStmt = $this->db->getPdo()->query("SHOW CREATE TABLE `{$tableName}`")->fetch(\PDO::FETCH_ASSOC);
                $createSql = array_values($createStmt)[1];

                fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n" . $createSql . ";\n\n");

                // Export data using raw PDO to avoid duplicated prefixes
                $count = 0;
                $stmt = $this->db->getPdo()->query("SELECT * FROM `{$tableName}`");

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $columns = array_keys($row);
                    $values = array_map(function($value) {
                        return $value === null ? 'NULL' : $this->db->getPdo()->quote($value);
                    }, array_values($row));

                    $sql = "INSERT INTO `{$tableName}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                    fwrite($handle, $sql);
                    $count++;
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
