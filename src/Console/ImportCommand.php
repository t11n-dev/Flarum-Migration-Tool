<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Nauhyuh\MigrationTool\Service\MigrationService;

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

        if (!$force && !$this->confirm('This will overwrite ALL existing data. Continue?', false)) {
            $this->info('Import cancelled.');
            return 0;
        }

        $this->info('Starting import...');

        try {
            $service = new MigrationService();
            
            // For import command, we assume we want to keep current config but import DB and assets
            // We'll use service utilities for parts of it
            
            $extractPath = storage_path('migration_import_' . time());
            mkdir($extractPath, 0755, true);

            $this->info('Extracting backup...');
            $zip = new \ZipArchive();
            if ($zip->open($backupFile) !== true) throw new \Exception('Cannot open backup file');
            $zip->extractTo($extractPath);
            $zip->close();

            // Import DB using service
            $this->info('Importing database...');
            $config = app('flarum.config');
            $dbConfig = $config['database'];
            
            $service->importDatabase(
                $extractPath . '/database.sql',
                $dbConfig['host'],
                $dbConfig['database'],
                $dbConfig['username'],
                $dbConfig['password']
            );

            // Copy assets
            if (is_dir($extractPath . '/assets')) {
                $this->info('Copying assets...');
                $service->recursiveCopy($extractPath . '/assets', public_path('assets'));
            }

            // Copy extensions storage
            if (is_dir($extractPath . '/storage/extensions')) {
                $this->info('Copying extensions storage...');
                $service->recursiveCopy($extractPath . '/storage/extensions', storage_path('extensions'));
            }

            $service->deleteDirectory($extractPath);

            $this->info('✓ Import completed successfully!');
            $this->warn('Running migrations and clearing cache...');
            $this->call('migrate');
            $this->call('cache:clear');

        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
