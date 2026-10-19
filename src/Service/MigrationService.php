<?php

namespace T11N\MigrationTool\Service;

use ZipArchive;

class MigrationService
{
    /**
     * Files inside the backup root that are metadata, never copied into the site.
     */
    const BACKUP_META_ITEMS = ['database.sql', 'settings.json', 'metadata.json', 'migrate.sh'];

    /**
     * Root items that are never renamed to _old during a restore.
     */
    const PROTECTED_ITEMS = ['storage', 'config.php', 'vendor', '.git', 'node_modules', '.env', 'migration_temp', 'migration_upload'];

    protected $db;
    protected $backupPath;
    protected $logPath;
    protected $progressPath;
    protected $lockPath;
    protected $lockHandle;

    public function __construct()
    {
        $this->db = app('db.connection');
        $this->backupPath = storage_path('backups');
        $this->logPath = storage_path('logs/migration.log');
        $this->progressPath = storage_path('logs/migration_progress.json');
        $this->lockPath = storage_path('logs/migration.lock');

        if (!is_dir($this->backupPath)) {
            @mkdir($this->backupPath, 0755, true);
        }
    }

    protected function assertZipAvailable()
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception("PHP 'zip' extension is not installed. ZIP functionality is required for backups.");
        }
    }

    /**
     * Prevent a backup and a migration (or two of either) from running at the same time.
     */
    protected function acquireLock()
    {
        $this->lockHandle = fopen($this->lockPath, 'c');
        if (!$this->lockHandle || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            throw new \Exception('Another backup or migration is already running. Please wait for it to finish.');
        }
    }

    protected function releaseLock()
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Update progress status
     */
    public function updateProgress($status, $percent, $label)
    {
        $data = [
            'status' => $status,
            'percent' => $percent,
            'label' => $label,
            'updated_at' => time()
        ];
        file_put_contents($this->progressPath, json_encode($data));
    }

    /**
     * Get current progress
     */
    public function getProgress()
    {
        if (!file_exists($this->progressPath)) {
            return ['status' => 'idle', 'percent' => 0, 'label' => ''];
        }
        return json_decode(file_get_contents($this->progressPath), true);
    }

    /**
     * Create a full backup as ZIP
     */
    public function createBackup($includeUploads = true, $includeVendor = false, $includeConfig = false)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $this->assertZipAvailable();
        $this->acquireLock();

        $this->updateProgress('running', 5, 'Initializing backup...');
        $this->log('Starting backup creation process...');

        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "flarum_backup_{$timestamp}";
        $tempDir = storage_path('migration_temp/' . $backupName);

        try {
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // 1. Export database
            $this->updateProgress('running', 10, 'Exporting database...');
            $this->log('Step 1: Exporting database...');
            $rowCounts = $this->exportDatabase($tempDir . '/database.sql');

            // 2. Export settings
            $this->updateProgress('running', 25, 'Exporting settings...');
            $this->log('Step 2: Exporting settings...');
            $this->exportSettings($tempDir . '/settings.json');

            // 3. Copy application files
            $this->updateProgress('running', 40, 'Copying application files (this may take a while)...');
            $this->log('Step 3: Copying application files...');
            $this->copySourceFiles($tempDir, $includeUploads, $includeVendor, $includeConfig);

            // 4. Create internal metadata
            $this->updateProgress('running', 70, 'Creating metadata...');
            $this->log('Step 4: Creating metadata and scripts...');
            $this->createMetadata($tempDir, $includeUploads, $includeVendor, $rowCounts);
            $this->createMigrationScript($tempDir);

            // 5. Pack everything
            $this->updateProgress('running', 80, 'Compressing to ZIP...');
            $this->log('Step 5: Compressing to ZIP...');
            $zipPath = $this->backupPath . '/' . $backupName . '.zip';
            $fileCount = $this->createZip($tempDir, $zipPath);

            $this->updateProgress('running', 90, 'Verifying backup...');
            $this->log('Step 6: Verifying ZIP integrity...');
            $this->verifyZip($zipPath, $fileCount);

            chmod($zipPath, 0664);

            $this->updateProgress('running', 95, 'Cleaning up temp files...');
            $this->log('Step 7: Cleaning up temp files...');
            $this->deleteDirectory($tempDir);

            $size = filesize($zipPath);
            $sizeFormatted = $this->formatBytes($size);
            $this->log("✓ SUCCESS! Backup created: {$backupName}.zip ({$sizeFormatted})");

            $this->updateProgress('complete', 100, 'Backup finished!');

            return [
                'success' => true,
                'filename' => $backupName . '.zip',
                'path' => $zipPath,
                'size' => $size,
                'size_formatted' => $sizeFormatted,
                'timestamp' => $timestamp
            ];

        } catch (\Throwable $e) {
            $this->updateProgress('error', 0, 'Backup failed: ' . $e->getMessage());
            $this->log('FATAL ERROR: ' . $e->getMessage(), 'error');
            if (is_dir($tempDir)) {
                $this->deleteDirectory($tempDir);
            }
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Perform the actual migration/restore
     */
    public function performMigration($zipPath, $configData)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        if (!is_file($zipPath)) {
            throw new \Exception("Backup file not found: " . basename($zipPath));
        }

        $this->assertZipAvailable();
        $this->acquireLock();

        $this->updateProgress('running', 5, 'Starting migration...');
        $this->log("Starting migration process from: " . basename($zipPath));
        $extractDir = storage_path('migration_temp/' . uniqid('extract_'));

        try {
            // 1. Extract backup
            $this->updateProgress('running', 15, 'Extracting backup...');
            $this->log("Step 1: Extracting backup...");
            $metadata = $this->extractBackup($zipPath, $extractDir);
            $warnings = [];

            // The imported tables keep the prefix of the source forum
            if (isset($metadata['db_prefix'])) {
                $configData['db_prefix'] = $metadata['db_prefix'];
            }

            // 2. Database Import
            $this->updateProgress('running', 35, 'Importing database...');
            $this->log("Step 2: Importing database...");
            $sqlFile = $extractDir . '/database.sql';
            if (file_exists($sqlFile)) {
                $this->importDatabase(
                    $sqlFile,
                    $configData['db_host'],
                    $configData['db_name'],
                    $configData['db_user'],
                    $configData['db_pass'],
                    $metadata['tables'] ?? null
                );
            } else {
                throw new \Exception('The backup does not contain database.sql.');
            }

            $warnings = array_merge($warnings, $this->compatibilityWarnings($metadata));

            // 3. File Replacement
            $this->updateProgress('running', 60, 'Replacing files and folders (Safety mode)...');
            $this->log("Step 3: Replacing files and folders (Safety: renaming existing items to _old)...");
            $this->replaceFilesFromBackup($extractDir, $metadata['include_uploads'] ?? true);

            // 4. Update config.php
            $this->updateProgress('running', 85, 'Updating configuration...');
            $this->log("Step 4: Updating configuration...");
            $this->updateConfig($configData);

            // Final Cleanup
            $this->updateProgress('running', 95, 'Clearing cache...');
            if (!$this->clearCache()) {
                $warnings[] = 'Could not clear the cache automatically. Run "php flarum cache:clear".';
            }
            $this->deleteDirectory($extractDir);
            foreach ($warnings as $warning) {
                $this->log('WARNING: ' . $warning, 'warning');
            }
            $this->log("✓ MIGRATION SUCCESSFUL!");

            $this->updateProgress('complete', 100, 'Migration finished!');

            return [
                'success' => true,
                'message' => 'Migration completed successfully. Old files have been renamed to _old.',
                'warnings' => $warnings,
            ];

        } catch (\Throwable $e) {
            $this->updateProgress('error', 0, 'Migration failed: ' . $e->getMessage());
            $this->log("FATAL ERROR DURING MIGRATE: " . $e->getMessage(), "error");
            if (is_dir($extractDir)) $this->deleteDirectory($extractDir);
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Extract and integrity-check a backup ZIP. Returns its metadata.
     */
    public function extractBackup($zipPath, $extractDir)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new \Exception('Could not open the ZIP file or it is corrupted. Please upload the backup again.');
        }
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new \Exception('Extracting the backup failed (corrupted file or not enough disk space).');
        }
        $zip->close();

        $metadata = [];
        if (file_exists($extractDir . '/metadata.json')) {
            $metadata = json_decode(file_get_contents($extractDir . '/metadata.json'), true) ?: [];
        }

        return $metadata;
    }

    /**
     * Differences between the source forum and this installation that may hide data
     */
    protected function compatibilityWarnings(array $metadata)
    {
        $warnings = [];

        $sourceVersion = $metadata['flarum_version'] ?? null;
        if ($sourceVersion && $sourceVersion !== \Flarum\Foundation\Application::VERSION) {
            $warnings[] = "The backup was made with Flarum {$sourceVersion}, this server runs " . \Flarum\Foundation\Application::VERSION . '. Run "php flarum migrate" to update the database.';
        }

        $enabled = $metadata['extensions_enabled'] ?? [];
        if ($enabled) {
            try {
                $installed = app(\Flarum\Extension\ExtensionManager::class)->getExtensions()->keys()->all();
                $missing = array_values(array_diff($enabled, $installed));
                if ($missing) {
                    $warnings[] = 'These extensions were enabled on the source forum but are not installed here, so their data will not be visible until you install them: ' . implode(', ', $missing);
                }
            } catch (\Throwable $e) {
                $this->log('Could not compare installed extensions: ' . $e->getMessage(), 'warning');
            }
        }

        return $warnings;
    }

    /**
     * Run Flarum's cache:clear so the restored settings, extensions and assets are picked up
     */
    protected function clearCache()
    {
        try {
            $command = app(\Flarum\Foundation\Console\CacheClearCommand::class);
            $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
            $this->log('Cache cleared.');
            return true;
        } catch (\Throwable $e) {
            $this->log('Cache clear failed: ' . $e->getMessage(), 'warning');
            return false;
        }
    }

    /**
     * Paths (relative to the site root) that hold user uploads
     */
    protected function uploadPaths()
    {
        $assets = ltrim(substr(public_path('assets'), strlen(base_path())), '/');

        return [$assets . '/avatars', $assets . '/files'];
    }

    /**
     * Copy all files and folders from root, applying exclusions
     */
    protected function copySourceFiles($tempDir, $includeUploads, $includeVendor, $includeConfig = false)
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

        $excludePaths = [];
        if (!$includeUploads) {
            foreach ($this->uploadPaths() as $path) {
                $excludePaths[] = $rootPath . '/' . $path;
            }
        }

        foreach ($items as $item) {
            if (in_array($item, $exclude) || str_ends_with($item, '_old')) continue;

            $this->copyForBackup($rootPath . '/' . $item, $tempDir . '/' . $item, $excludePaths);
        }

        // Special handling for storage: essential data only
        $storagePath = storage_path();
        $storageItems = array_diff(scandir($storagePath), ['.', '..', 'backups', 'logs', 'sessions', 'views', 'cache', 'migration_temp', 'migration_upload']);

        foreach ($storageItems as $sItem) {
            if (str_ends_with($sItem, '_old')) continue;
            @mkdir($tempDir . '/storage', 0755, true);
            $this->copyForBackup($storagePath . '/' . $sItem, $tempDir . '/storage/' . $sItem);
        }
    }

    /**
     * Import a SQL dump. Tables are replaced by the DROP/CREATE statements of the dump;
     * tables that are not part of the dump are left untouched.
     */
    public function importDatabase($sqlFile, $host, $database, $username, $password, ?array $expectedCounts = null)
    {
        $this->log("Importing database into {$database} @ {$host}...");

        $port = app('flarum.config')['database']['port'] ?? 3306;

        $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);

        // Same session settings as the export, independent of this server's defaults:
        // no strict mode (zero dates), keep explicit 0 ids, UTC timestamps
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

        $this->log(" -> Executing SQL dump...");
        $executed = 0;
        $failed = 0;
        $firstError = null;

        $this->parseSqlFile($sqlFile, function ($statement) use ($pdo, &$executed, &$failed, &$firstError) {
            try {
                // query() + closeCursor() also handles statements that return rows
                $pdo->query($statement)->closeCursor();
                $executed++;
            } catch (\Exception $e) {
                $failed++;
                $firstError = $firstError ?: $e->getMessage();
                $this->log("SQL error: " . substr($e->getMessage(), 0, 200) . " | Statement: " . substr($statement, 0, 120), "error");
            }
        });

        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        if ($failed > 0) {
            throw new \Exception("Database import finished with {$failed} failed statement(s) out of " . ($executed + $failed) . ". First error: " . substr($firstError, 0, 200));
        }

        $this->log("Database imported successfully ({$executed} statements).");

        if ($expectedCounts === null) {
            $this->log(" -> Backup has no row counts (created by an older version), skipping verification.", 'warning');
            return;
        }

        $this->log(" -> Verifying row counts of " . count($expectedCounts) . " tables...");
        $mismatches = [];
        foreach ($expectedCounts as $table => $expected) {
            try {
                $actual = (int) $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "`")->fetchColumn();
            } catch (\Exception $e) {
                $actual = 'missing';
            }
            if ($actual !== (int) $expected) {
                $mismatches[] = "{$table} (expected {$expected}, got {$actual})";
            }
        }

        if ($mismatches) {
            throw new \Exception('Database verification failed, imported data is incomplete: ' . implode('; ', $mismatches));
        }

        $this->log(" -> All tables verified.");
    }

    /**
     * Stream a SQL file and pass each statement to the callback.
     * Delimiters and comments are only recognised outside of quoted strings,
     * so post content containing ";", "--" or "/*" is preserved.
     */
    protected function parseSqlFile($sqlFile, callable $onStatement)
    {
        $handle = fopen($sqlFile, 'rb');
        if (!$handle) {
            throw new \Exception("Cannot open SQL file: " . basename($sqlFile));
        }

        $statement = '';
        $quote = null;
        $escaped = false;
        $inBlockComment = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $len = strlen($line);
                $i = 0;

                while ($i < $len) {
                    if ($inBlockComment) {
                        $end = strpos($line, '*/', $i);
                        if ($end === false) {
                            break;
                        }
                        $inBlockComment = false;
                        $i = $end + 2;
                        continue;
                    }

                    if ($quote !== null) {
                        if ($escaped) {
                            $statement .= $line[$i++];
                            $escaped = false;
                            continue;
                        }
                        $span = strcspn($line, $quote . '\\', $i);
                        $statement .= substr($line, $i, $span);
                        $i += $span;
                        if ($i >= $len) break;

                        $c = $line[$i++];
                        $statement .= $c;
                        if ($c === '\\') {
                            $escaped = $quote !== '`';
                        } else {
                            $quote = null;
                        }
                        continue;
                    }

                    $span = strcspn($line, "'\"`;-#/", $i);
                    $statement .= substr($line, $i, $span);
                    $i += $span;
                    if ($i >= $len) break;

                    $c = $line[$i];
                    $next = $line[$i + 1] ?? '';

                    if ($c === ';') {
                        $statement = trim($statement);
                        if ($statement !== '') {
                            $onStatement($statement);
                        }
                        $statement = '';
                        $i++;
                    } elseif ($c === '#' || ($c === '-' && $next === '-' && ctype_space($line[$i + 2] ?? "\n"))) {
                        // Line comment: skip the rest of the line
                        $statement .= "\n";
                        break;
                    } elseif ($c === '/' && $next === '*') {
                        $inBlockComment = true;
                        $i += 2;
                    } else {
                        if ($c === "'" || $c === '"' || $c === '`') {
                            $quote = $c;
                        }
                        $statement .= $c;
                        $i++;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $statement = trim($statement);
        if ($statement !== '') {
            $onStatement($statement);
        }
    }

    /**
     * Rename the existing items that the backup replaces to _old and copy the new ones.
     * If anything fails, the renamed items are restored.
     */
    protected function replaceFilesFromBackup($extractDir, $backupHasUploads = true)
    {
        $rootPath = base_path();

        $backupItems = array_values(array_diff(
            scandir($extractDir),
            array_merge(['.', '..', 'config.php', 'storage', '.env'], self::BACKUP_META_ITEMS)
        ));

        // Without vendor in the backup, its composer files would not match the installed packages
        if (!in_array('vendor', $backupItems)) {
            foreach (['composer.json', 'composer.lock'] as $composerFile) {
                if (in_array($composerFile, $backupItems)) {
                    copy($extractDir . '/' . $composerFile, $rootPath . '/' . $composerFile . '.from_backup');
                    $this->log(" -> Kept current {$composerFile}; the backup version was saved as {$composerFile}.from_backup. Run composer to install the source forum's extensions if needed.", 'warning');
                }
            }
            $backupItems = array_values(array_diff($backupItems, ['composer.json', 'composer.lock']));
        }

        $renamed = [];

        try {
            $this->log(" -> Renaming items replaced by the backup to _old...");
            foreach ($backupItems as $item) {
                if (in_array($item, self::PROTECTED_ITEMS)) continue;

                $current = $rootPath . '/' . $item;
                if (!file_exists($current) && !is_link($current)) continue;

                $this->renameToOld($current);
                $renamed[] = $item;
            }

            $this->log(" -> Copying new files from backup...");
            foreach ($backupItems as $item) {
                $this->copyItem($extractDir . '/' . $item, $rootPath . '/' . $item);
            }

            if (!$backupHasUploads) {
                $this->log(" -> Backup has no uploads, carrying over existing uploaded files...");
                foreach ($this->uploadPaths() as $path) {
                    $segments = explode('/', $path, 2);
                    $oldPath = $rootPath . '/' . $segments[0] . '_old' . (isset($segments[1]) ? '/' . $segments[1] : '');
                    if (in_array($segments[0], $renamed) && is_dir($oldPath)) {
                        $this->recursiveCopy($oldPath, $rootPath . '/' . $path);
                    }
                }
            }

            $srcAssets = $extractDir . '/storage/assets';
            if (is_dir($srcAssets)) {
                $this->log(" -> Updating assets (safety: renaming old to _old)...");
                foreach (array_unique([public_path('assets'), base_path('assets')]) as $dstAssets) {
                    if (is_dir($dstAssets)) {
                        $this->renameToOld($dstAssets);
                        $this->recursiveCopy($srcAssets, $dstAssets);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log(" -> File replacement failed, restoring original items...", 'error');
            foreach (array_reverse($renamed) as $item) {
                $current = $rootPath . '/' . $item;
                if (is_dir($current) && !is_link($current)) {
                    $this->deleteDirectory($current);
                } elseif (file_exists($current) || is_link($current)) {
                    @unlink($current);
                }
                @rename($current . '_old', $current);
            }
            throw $e;
        }

        if (is_dir($extractDir . '/storage')) {
            $this->log(" -> Merging other storage directories...");
            $this->recursiveCopy($extractDir . '/storage', storage_path());
        }
    }

    protected function renameToOld($path)
    {
        $oldPath = $path . '_old';

        if (is_dir($oldPath) && !is_link($oldPath)) {
            $this->deleteDirectory($oldPath);
        } elseif (file_exists($oldPath) || is_link($oldPath)) {
            @unlink($oldPath);
        }

        if (!rename($path, $oldPath)) {
            throw new \Exception('Could not rename ' . basename($path) . ' to ' . basename($oldPath) . '. Check file permissions.');
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
                is_dir($p) && !is_link($p) ? $this->deleteDirectory($p) : @unlink($p);
                $count++;
            }
        }

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
        if (isset($data['db_prefix'])) $config['database']['prefix'] = $data['db_prefix'];
        if (!empty($data['new_url'])) $config['url'] = $data['new_url'];

        $content = "<?php return " . var_export($config, true) . ";";
        file_put_contents($configPath, $content);
        $this->log("Configuration updated in config.php");
    }

    /**
     * Database Export Logic - Pure PHP
     */
    /**
     * Export the forum's tables. Returns the number of rows exported per table,
     * which is stored in the metadata and checked again after import.
     */
    protected function exportDatabase($filePath)
    {
        $this->log("Starting native PHP database export...");

        $pdo = $this->db->getPdo();
        $prefix = $this->db->getTablePrefix();
        $rowCounts = [];

        // Export TIMESTAMP values in UTC so they are not shifted when the servers use different time zones
        $originalTimeZone = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        // Read every table from the same point in time, even while the forum is in use
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            $handle = fopen($filePath, 'w');
            if (!$handle) throw new \Exception("Cannot open file for writing: " . basename($filePath));

            fwrite($handle, "-- Flarum Native PHP Backup\n-- Date: " . date('Y-m-d H:i:s') . "\n\n"
                . "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\nSET TIME_ZONE='+00:00';\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);

            // Only export this forum's tables when the database is shared via a prefix
            if ($prefix !== '') {
                $tables = array_values(array_filter($tables, fn($t) => str_starts_with($t, $prefix)));
            }

            foreach ($tables as $tableName) {
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$tableName}`")->fetch(\PDO::FETCH_ASSOC);
                $createSql = array_values($createStmt)[1];

                fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n" . $createSql . ";\n\n");

                // Binary values are written as hex literals so they survive any connection charset;
                // BIT values are read as numbers because PDO returns them inconsistently
                $binaryColumns = [];
                $bitColumns = [];
                $select = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `{$tableName}`")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                    $field = str_replace('`', '``', $column['Field']);
                    if (preg_match('/^bit/i', $column['Type'])) {
                        $select[] = "`{$field}` + 0 AS `{$field}`";
                        $bitColumns[$column['Field']] = true;
                    } else {
                        $select[] = "`{$field}`";
                        if (preg_match('/blob|binary/i', $column['Type'])) {
                            $binaryColumns[$column['Field']] = true;
                        }
                    }
                }

                $count = 0;

                // Stream rows instead of loading the whole table into memory
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    $stmt = $pdo->query("SELECT " . implode(', ', $select) . " FROM `{$tableName}`");
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $values = [];
                        foreach ($row as $column => $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } elseif (isset($bitColumns[$column])) {
                                $values[] = (string) (int) $value;
                            } elseif (isset($binaryColumns[$column])) {
                                $values[] = $value === '' ? "''" : '0x' . bin2hex($value);
                            } else {
                                $values[] = $pdo->quote($value);
                            }
                        }

                        $sql = "INSERT INTO `{$tableName}` (`" . implode("`, `", array_keys($row)) . "`) VALUES (" . implode(", ", $values) . ");\n";
                        fwrite($handle, $sql);
                        $count++;
                    }
                    $stmt->closeCursor();
                } finally {
                    $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
                fwrite($handle, "\n");

                $rowCounts[$tableName] = $count;
                $this->log(" -> {$tableName}: {$count} rows");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (fclose($handle) === false) {
                throw new \Exception('Failed to write the database dump. Check free disk space.');
            }
            return $rowCounts;
        } catch (\Throwable $e) {
            $this->log("PHP Export Error: " . $e->getMessage(), 'error');
            throw $e;
        } finally {
            $pdo->exec('COMMIT');
            $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($originalTimeZone));
        }
    }

    protected function exportSettings($filePath)
    {
        $settings = $this->db->table('settings')->get();
        $data = [];
        foreach ($settings as $s) $data[$s->key] = $s->value;
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function createMetadata($tempDir, $includeUploads, $includeVendor, array $rowCounts = [])
    {
        $metadata = [
            'app' => 'Flarum',
            'package' => 't11n-dev/flarum-migration-tool',
            'date' => date('Y-m-d H:i:s'),
            'flarum_version' => \Flarum\Foundation\Application::VERSION,
            'php' => PHP_VERSION,
            'db_name' => $this->db->getDatabaseName(),
            'db_prefix' => $this->db->getTablePrefix(),
            'url' => app('flarum.config')['url'],
            'include_uploads' => (bool) $includeUploads,
            'include_vendor' => (bool) $includeVendor,
            'extensions_enabled' => json_decode((string) $this->db->table('settings')->where('key', 'extensions_enabled')->value('value'), true) ?: [],
            'tables' => $rowCounts,
        ];
        file_put_contents($tempDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
    }

    protected function createMigrationScript($tempDir)
    {
        $script = "#!/bin/bash\necho \"Flarum Restore Script\"\n";
        file_put_contents($tempDir . '/migrate.sh', $script);
        chmod($tempDir . '/migrate.sh', 0755);
    }

    /**
     * Pack a directory into a ZIP, keeping empty directories. Returns the number of files added.
     */
    protected function createZip($sourceDir, $zipPath)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \Exception("Zip failed");
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        $count = 0;
        foreach ($items as $item) {
            $name = substr($item->getPathname(), strlen($sourceDir) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($name);
            } else {
                if (!$zip->addFile($item->getPathname(), $name)) {
                    throw new \Exception("Could not add {$name} to the ZIP.");
                }
                $count++;
            }
        }
        if (!$zip->close()) {
            throw new \Exception('Failed to write the ZIP file. Check free disk space.');
        }
        return $count;
    }

    /**
     * Check that a freshly created backup is readable and contains every file
     */
    protected function verifyZip($zipPath, $expectedFiles)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new \Exception('Backup verification failed: the ZIP file is corrupted.');
        }

        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!str_ends_with($zip->getNameIndex($i), '/')) $files++;
        }
        $hasDump = $zip->locateName('database.sql') !== false;
        $zip->close();

        if ($files !== $expectedFiles || !$hasDump) {
            throw new \Exception("Backup verification failed: expected {$expectedFiles} files, found {$files}.");
        }

        $this->log(" -> ZIP verified ({$files} files).");
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

    /**
     * Copy into a backup, following symlinks so their real content is included
     * (a ZIP cannot hold symlinks, and absolute links would not resolve on another server).
     */
    protected function copyForBackup($src, $dst, array $excludePaths = [], array &$visited = [])
    {
        if (in_array($src, $excludePaths)) return;

        $real = realpath($src);
        if ($real === false) {
            $this->log("Skipping broken symlink: " . substr($src, strlen(base_path()) + 1), 'warning');
            return;
        }

        if (is_dir($real)) {
            // Guard against symlink loops
            if (isset($visited[$real])) return;
            $visited[$real] = true;

            @mkdir($dst, 0755, true);
            foreach (scandir($real) as $f) {
                if ($f === '.' || $f === '..') continue;
                $this->copyForBackup("$src/$f", "$dst/$f", $excludePaths, $visited);
            }
            unset($visited[$real]);
        } elseif (!copy($real, $dst)) {
            throw new \Exception('Could not copy ' . substr($src, strlen(base_path()) + 1) . ' into the backup. Check permissions and free disk space.');
        }
    }

    /**
     * Copy a file, directory or symlink
     */
    protected function copyItem($src, $dst, array $excludePaths = [])
    {
        if (is_link($src)) {
            if (file_exists($dst) || is_link($dst)) @unlink($dst);
            symlink(readlink($src), $dst);
        } elseif (is_dir($src)) {
            $this->recursiveCopy($src, $dst, $excludePaths);
        } else {
            copy($src, $dst);
        }
    }

    /**
     * Recursively copy a directory. Symlinks are recreated, not followed.
     */
    public function recursiveCopy($src, $dst, array $excludePaths = [])
    {
        if (!is_dir($src) || in_array($src, $excludePaths)) return;
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (in_array("$src/$f", $excludePaths)) continue;
            $this->copyItem("$src/$f", "$dst/$f", $excludePaths);
        }
    }

    public function deleteDirectory($dir)
    {
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = "$dir/$f";
            is_dir($p) && !is_link($p) ? $this->deleteDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }

    protected function formatBytes($b, $p = 2)
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $b > 1024 && $i < count($u) - 1; $i++) $b /= 1024;
        return round($b, $p) . ' ' . $u[$i];
    }

    /**
     * Current connection settings for prefilling the admin form. The password is never exposed.
     */
    public function getCurrentConfig()
    {
        $config = app('flarum.config');
        return [
            'db_host' => $config['database']['host'] ?? 'localhost',
            'db_name' => $config['database']['database'] ?? '',
            'db_user' => $config['database']['username'] ?? '',
            'has_db_pass' => !empty($config['database']['password']),
            'url' => $config['url'] ?? '',
        ];
    }

    /**
     * Current database password, used when the admin leaves the password field empty
     */
    public function getCurrentDbPassword()
    {
        return app('flarum.config')['database']['password'] ?? '';
    }
}
