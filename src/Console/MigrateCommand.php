<?php

namespace Nauhyuh\MigrationTool\Console;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;

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
        $this->info('');

        $backupFile = $this->input->getArgument('backup');

        if (!file_exists($backupFile)) {
            $this->error("Backup file not found: {$backupFile}");
            return 1;
        }

        // Ask for confirmation
        $this->warn('⚠️  WARNING: This will overwrite ALL existing data!');
        if (!$this->confirm('Are you absolutely sure you want to continue?', false)) {
            $this->info('Migration cancelled.');
            return 0;
        }

        // Ask for database credentials
        $this->info('');
        $this->info('Database Configuration:');
        $dbHost = $this->ask('Database host', 'localhost');
        $dbName = $this->ask('Database name');
        $dbUser = $this->ask('Database username');
        $dbPass = $this->secret('Database password');

        // Ask for new URL
        $this->info('');
        $newUrl = $this->ask('New site URL (e.g., https://example.com)');

        try {
            // Extract backup
            $this->task('Extracting backup', function() use ($backupFile, &$extractPath) {
                $extractPath = storage_path('migration_' . time());
                mkdir($extractPath, 0755, true);

                $zip = new \ZipArchive();
                if ($zip->open($backupFile) !== true) {
                    throw new \Exception('Cannot open backup file');
                }
                $zip->extractTo($extractPath);
                $zip->close();

                return $extractPath;
            });

            // Import database
            $this->task('Importing database', function() use ($extractPath, $dbHost, $dbName, $dbUser, $dbPass) {
                $this->importDatabase(
                    $extractPath . '/database.sql',
                    $dbHost,
                    $dbName,
                    $dbUser,
                    $dbPass
                );
            });

            // Copy files
            $this->task('Copying files', function() use ($extractPath) {
                if (is_dir($extractPath . '/assets')) {
                    $this->recursiveCopy($extractPath . '/assets', public_path('assets'));
                }
                if (is_dir($extractPath . '/storage')) {
                    $this->recursiveCopy($extractPath . '/storage', storage_path());
                }
            });

            // Update config
            $this->task('Updating configuration', function() use ($newUrl, $dbHost, $dbName, $dbUser, $dbPass) {
                $this->updateConfig($newUrl, $dbHost, $dbName, $dbUser, $dbPass);
            });

            // Install composer dependencies
            if (file_exists($extractPath . '/composer.json')) {
                $this->task('Installing dependencies', function() {
                    exec('composer install --no-dev --optimize-autoloader 2>&1', $output, $returnVar);
                    if ($returnVar !== 0) {
                        $this->warn('Composer install had some issues. You may need to run it manually.');
                    }
                });
            }

            // Run migrations
            $this->task('Running migrations', function() {
                $this->call('migrate');
                $this->call('cache:clear');
            });

            // Set permissions
            $this->task('Setting permissions', function() {
                chmod(storage_path(), 0775);
                chmod(public_path('assets'), 0775);
            });

            // Cleanup
            $this->deleteDirectory($extractPath);

            $this->info('');
            $this->info('╔════════════════════════════════════════╗');
            $this->info('║  ✓ Migration Completed Successfully!  ║');
            $this->info('╚════════════════════════════════════════╝');
            $this->info('');
            $this->info("Your site is now available at: {$newUrl}");
            $this->info('');
            $this->info('Next steps:');
            $this->info('1. Visit your site and verify everything works');
            $this->info('2. Check admin panel settings');
            $this->info('3. Test all extensions');
            $this->info('');

        } catch (\Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function importDatabase($sqlFile, $host, $dbName, $user, $pass)
    {
        $pdo = new \PDO("mysql:host={$host};dbname={$dbName}", $user, $pass);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $sql = file_get_contents($sqlFile);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) { return !empty($stmt); }
        );

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
    }

    protected function updateConfig($newUrl, $dbHost, $dbName, $dbUser, $dbPass)
    {
        $config = [
            'debug' => false,
            'database' => [
                'driver' => 'mysql',
                'host' => $dbHost,
                'port' => 3306,
                'database' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'engine' => 'InnoDB',
                'prefix_indexes' => true,
            ],
            'url' => $newUrl,
            'paths' => [
                'api' => 'api',
                'admin' => 'admin',
            ],
        ];

        $configContent = "<?php return " . var_export($config, true) . ";";
        file_put_contents(base_path('config.php'), $configContent);
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
