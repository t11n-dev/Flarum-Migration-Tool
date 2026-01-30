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
            $this->log('Step 3: Copying Flarum files...');
            $this->copyConfig($tempDir);
            $this->copyComposerFiles($tempDir);
            
            if (file_exists(base_path('.env'))) {
                copy(base_path('.env'), $tempDir . '/.env');
            }

            if ($includeUploads) {
                $this->log(' -> Copying public/assets...');
                $this->copyUploads($tempDir);
            }

            if ($includeVendor) {
                $this->log(' -> Copying vendor directory...');
                $this->copyVendor($tempDir);
            }

            $this->log(' -> Copying storage/extensions...');
            $this->copyExtensionsData($tempDir);

            // 4. Create internal metadata
            $this->log('Step 4: Creating metadata and scripts...');
            $this->createMetadata($tempDir, $includeUploads, $includeVendor);
            $this->createMigrationScript($tempDir);

            // 5. Pack everything
            $this->log('Step 5: Compressing to ZIP...');
            $zipPath = $this->backupPath . '/' . $backupName . '.zip';
            $this->createZip($tempDir, $zipPath);

            // Cleanup
            $this->log('Step 6: Cleaning up temp files...');
            $this->deleteDirectory(storage_path('migration_temp'));
            
            $size = filesize($zipPath);
            $sizeFormatted = $this->formatBytes($size);
            $this->log("✓ SUCCESS! Backup for v4r.net created: {$backupName}.zip ({$sizeFormatted})");

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
     * Database Export Logic with Fallback
     */
    protected function exportDatabase($filePath)
    {
        $config = app('flarum.config');
        $dbConfig = $config['database'];
        
        $success = false;

        // Try mysqldump first
        if (function_exists('exec')) {
            $cnfFile = storage_path('migration_temp/mysql_' . uniqid() . '.cnf');
            if (!is_dir(dirname($cnfFile))) @mkdir(dirname($cnfFile), 0755, true);

            $cnfContent = "[client]\n" .
                          "user=\"" . addslashes($dbConfig['username']) . "\"\n" .
                          "password=\"" . addslashes($dbConfig['password']) . "\"\n" .
                          "host=\"" . addslashes($dbConfig['host']) . "\"\n" .
                          "port=\"" . addslashes($dbConfig['port'] ?? 3306) . "\"";
            
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            try {
                $this->log("Attempting mysqldump...");
                $cmd = sprintf(
                    'mysqldump --defaults-extra-file=%s --single-transaction --quick --no-tablespaces %s > %s',
                    escapeshellarg($cnfFile),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($filePath)
                );
                
                $output = [];
                $returnVar = 0;
                exec($cmd . ' 2>&1', $output, $returnVar);
                
                if ($returnVar === 0) {
                    $this->log("mysqldump succeeded.");
                    $success = true;
                } else {
                    $this->log("mysqldump failed with code {$returnVar}: " . implode("\n", $output), 'warning');
                }
            } catch (\Throwable $e) {
                $this->log("mysqldump exception: " . $e->getMessage(), 'warning');
            }

            if (file_exists($cnfFile)) @unlink($cnfFile);
        }

        // Fallback to PHP-based export
        if (!$success) {
            $this->log("Falling back to pure PHP database export (this may take a minute)...");
            $this->phpExportDatabase($filePath);
        }
    }

    /**
     * Robust PHP-based Database Exporter
     */
    protected function phpExportDatabase($filePath)
    {
        $dbName = $this->db->getDatabaseName();
        $tables = $this->db->select('SHOW TABLES');
        $keyName = "Tables_in_{$dbName}";

        $handle = fopen($filePath, 'w');
        fwrite($handle, "-- Flarum PHP Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        foreach ($tables as $table) {
            $tableName = $table->$keyName;
            
            // Get create table statement
            $createTable = $this->db->select("SHOW CREATE TABLE `{$tableName}`")[0];
            $createKey = 'Create Table';
            fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n" . $createTable->$createKey . ";\n\n");

            // Export rows
            $this->db->table($tableName)->chunk(500, function ($rows) use ($handle, $tableName) {
                foreach ($rows as $row) {
                    $rowArray = (array)$row;
                    $columns = array_keys($rowArray);
                    $values = array_map(function($value) {
                        if ($value === null) return 'NULL';
                        return $this->db->getPdo()->quote($value);
                    }, array_values($rowArray));

                    $sql = "INSERT INTO `{$tableName}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                    fwrite($handle, $sql);
                }
            });
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        $this->log("PHP database export completed.");
    }

    /**
     * Filesystem Helpers
     */
    protected function exportSettings($filePath)
    {
        $settings = $this->db->table('settings')->get();
        $data = [];
        foreach ($settings as $s) $data[$s->key] = $s->value;
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function copyConfig($tempDir)
    {
        if (file_exists(base_path('config.php'))) copy(base_path('config.php'), $tempDir . '/config.php');
    }

    protected function copyComposerFiles($tempDir)
    {
        foreach (['composer.json', 'composer.lock'] as $f) {
            if (file_exists(base_path($f))) copy(base_path($f), $tempDir . '/' . $f);
        }
    }

    protected function copyUploads($tempDir)
    {
        $src = public_path('assets');
        if (is_dir($src)) $this->recursiveCopy($src, $tempDir . '/assets');
    }

    protected function copyVendor($tempDir)
    {
        $src = base_path('vendor');
        if (is_dir($src)) $this->recursiveCopy($src, $tempDir . '/vendor');
    }

    protected function copyExtensionsData($tempDir)
    {
        $src = storage_path('extensions');
        if (is_dir($src)) $this->recursiveCopy($src, $tempDir . '/storage/extensions');
    }

    protected function createMetadata($tempDir, $includeUploads, $includeVendor)
    {
        $metadata = [
            'app' => 'Flarum',
            'package' => 'nauhyuh/flarum-migration-tool',
            'date' => date('Y-m-d H:i:s'),
            'flarum_version' => app()->version(),
            'php' => PHP_VERSION,
            'db_name' => $this->db->getDatabaseName(),
            'url' => url('/'),
            'include_uploads' => $includeUploads,
            'include_vendor' => $includeVendor,
        ];
        file_put_contents($tempDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
    }

    protected function createMigrationScript($tempDir)
    {
        // Simple script for now
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
        $p = $this->backupPath . '/' . $filename;
        if (file_exists($p)) unlink($p);
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
}
