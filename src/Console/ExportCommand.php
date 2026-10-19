<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;
use Nauhyuh\MigrationTool\Service\MigrationService;

class ExportCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('migration:export')
            ->setDescription('Export Flarum data for migration')
            ->addOption('include-uploads', null, InputOption::VALUE_NONE, 'Include uploaded files')
            ->addOption('include-vendor', null, InputOption::VALUE_NONE, 'Include vendor directory');
    }

    protected function fire()
    {
        $this->info('Starting Flarum backup export...');
        
        $includeUploads = $this->input->getOption('include-uploads');
        $includeVendor = $this->input->getOption('include-vendor');

        try {
            $result = (new MigrationService())->createBackup($includeUploads, $includeVendor);

            if ($result['success']) {
                $this->info('✓ Backup created successfully!');
                $this->info("File: {$result['filename']}");
                $this->info("Size: {$result['size_formatted']}");
                $this->info("Path: {$result['path']}");
            } else {
                $this->error('Backup failed!');
            }

        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
