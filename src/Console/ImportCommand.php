<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use ZipArchive;

class ImportCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('migration:import')
            ->setDescription('Import Flarum backup')
            ->addArgument('backup', InputArgument::REQUIRED, 'Backup file path')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force import without confirmation');
    }

    protected function fire()
    {
        $backupFile = $this->input->getArgument('backup');
        $force = $this->input->getOption('force');

        if (!file_exists($backupFile)) {
            $this->error("Backup file not found: {$backupFile}");
            return 1;
        }

        if (!$force) {
            if (!$this->confirm('This will overwrite all existing data. Continue?', false)) {
                $this->info('Import cancelled.');
                return 0;
            }
        }

        $this->info('Starting import...');

        try {
            // Extract backup
            $extractPath = storage_path('migration_import_' . time());
            mkdir($extractPath, 0755, true);

            $this->info('Extracting backup...');
            $zip = new ZipArchive();
            if ($zip->open($backupFile) !== true) {
                throw new \Exception('Cannot open backup file');
            }
            $zip->extractTo($extractPath);
            $zip->close();

            // Check metadata
            $metadataPath = $extractPath . '/metadata.json';
            if (file_exists($metadataPath)) {
                $metadata = json_decode(file_get_contents($metadataPath), true);
                $this->info("Backup from: {$metadata['backup_date']}");
                $this->info("Flarum version: {$metadata['flarum_version']}");
            }

            // Import database
            $this->info('Importing database...');
            $this->importDatabase($extractPath . '/database.sql');

            // Copy files
            if (is_dir($extractPath . '/assets')) {
                $this->info('Copying assets...');
                $this->recursiveCopy($extractPath . '/assets', public_path('assets'));
            }

            // Copy storage
            if (is_dir($extractPath . '/storage')) {
                $this->info('Copying storage...');
                $this->recursiveCopy($extractPath . '/storage/extensions', storage_path('extensions'));
            }

            // Cleanup
            $this->deleteDirectory($extractPath);

            $this->info('✓ Import completed successfully!');
            $this->warn('Please run: php flarum migrate');
            $this->warn('Please run: php flarum cache:clear');

        } catch (\Exception $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function importDatabase($sqlFile)
    {
        if (!file_exists($sqlFile)) {
            throw new \Exception('Database file not found');
        }

        $db = app('flarum.db');
        $sql = file_get_contents($sqlFile);

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) { return !empty($stmt); }
        );

        $db->beginTransaction();
        try {
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $db->unprepared($statement);
                }
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    protected function recursiveCopy($src, $dst)
    {
        if (!is_dir($src)) return;
        
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->recursiveCopy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
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
}
