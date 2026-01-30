<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Nauhyuh\MigrationTool\Service\MigrationService;

class MigrateCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('migration:migrate')
            ->setDescription('Run full migration from backup')
            ->addArgument('backup', InputArgument::REQUIRED, 'Path to backup ZIP file');
    }

    protected function fire()
    {
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║   Flarum Auto Migration Tool          ║');
        $this->info('╚════════════════════════════════════════╝');

        $backupFile = $this->input->getArgument('backup');
        if (!file_exists($backupFile)) {
            $this->error("Backup file not found: {$backupFile}");
            return 1;
        }

        $this->warn('⚠️  WARNING: This will overwrite ALL existing data!');
        if (!$this->confirm('Are you absolutely sure you want to continue?', false)) {
            return 0;
        }

        $dbHost = $this->ask('Database host', 'localhost');
        $dbName = $this->ask('Database name');
        $dbUser = $this->ask('Database username');
        $dbPass = $this->secret('Database password');
        $newUrl = $this->ask('New site URL');

        try {
            $service = new MigrationService();
            
            $configData = [
                'new_url' => $newUrl,
                'db_host' => $dbHost,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_pass' => $dbPass,
            ];

            $this->info('Running migration...');
            $service->performMigration($backupFile, $configData);

            $this->info('✓ Migration Completed Successfully!');

        } catch (\Throwable $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
