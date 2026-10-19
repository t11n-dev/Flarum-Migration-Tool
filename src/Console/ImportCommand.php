<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Nauhyuh\MigrationTool\Service\MigrationService;

class ImportCommand extends AbstractCommand
{
    use InteractsWithConsole;

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
            
            $extractPath = storage_path('migration_temp/import_' . time());

            $this->info('Extracting backup...');
            $metadata = $service->extractBackup($backupFile, $extractPath);

            $config = app('flarum.config');
            $dbConfig = $config['database'];

            // The imported tables keep the source prefix; with a different prefix the forum would not see them
            $currentPrefix = $dbConfig['prefix'] ?? '';
            if (isset($metadata['db_prefix']) && $metadata['db_prefix'] !== $currentPrefix) {
                $service->deleteDirectory($extractPath);
                $this->error("The backup uses table prefix \"{$metadata['db_prefix']}\" but this forum uses \"{$currentPrefix}\". Use migration:migrate, which updates config.php, or change the prefix first.");
                return 1;
            }

            // Import DB using service
            $this->info('Importing database...');
            $service->importDatabase(
                $extractPath . '/database.sql',
                $dbConfig['host'],
                $dbConfig['database'],
                $dbConfig['username'],
                $dbConfig['password'],
                $metadata['tables'] ?? null
            );

            // Copy assets (public/assets inside the backup, or storage/assets from older backups)
            $publicRel = ltrim(substr(public_path(), strlen(base_path())), '/');
            $assetSources = [
                $extractPath . '/' . ($publicRel !== '' ? $publicRel . '/' : '') . 'assets',
                $extractPath . '/storage/assets',
            ];
            foreach ($assetSources as $assetSource) {
                if (is_dir($assetSource)) {
                    $this->info('Copying assets...');
                    $service->recursiveCopy($assetSource, public_path('assets'));
                }
            }

            // Copy extensions storage
            if (is_dir($extractPath . '/storage/extensions')) {
                $this->info('Copying extensions storage...');
                $service->recursiveCopy($extractPath . '/storage/extensions', storage_path('extensions'));
            }

            $service->deleteDirectory($extractPath);

            $this->info('✓ Import completed successfully!');
            $this->warn('Running migrations and clearing cache...');
            $this->callCommand('migrate');
            $this->callCommand('cache:clear');

        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
