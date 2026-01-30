<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Nauhyuh\MigrationTool\Service\MigrationService;
use ZipArchive;

class MigrateController implements RequestHandlerInterface
{
    protected $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $body = $request->getParsedBody();
        $filename = Arr::get($body, 'filename');
        $newUrl = Arr::get($body, 'new_url');
        $dbHost = Arr::get($body, 'db_host', 'localhost');
        $dbName = Arr::get($body, 'db_name');
        $dbUser = Arr::get($body, 'db_user');
        $dbPass = Arr::get($body, 'db_pass');

        try {
            $this->migrationService->log("Starting migration process...");
            
            // Extract backup
            $uploadPath = storage_path('migration_upload/' . $filename);
            if (!file_exists($uploadPath)) {
                throw new \Exception('Backup file not found');
            }

            $extractPath = storage_path('migration_extract');
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0755, true);
            }

            // Extract ZIP
            $this->migrationService->log("Extracting backup file...");
            $zip = new ZipArchive();
            if ($zip->open($uploadPath) !== true) {
                throw new \Exception('Cannot open backup file');
            }
            $zip->extractTo($extractPath);
            $zip->close();

            // Import database
            $this->migrationService->log("Importing database...");
            $this->importDatabase($extractPath . '/database.sql', $dbHost, $dbName, $dbUser, $dbPass);

            // Copy files
            $this->migrationService->log("Copying files...");
            $this->copyFiles($extractPath);

            // Update config
            $this->migrationService->log("Updating configuration...");
            $this->updateConfig($newUrl, $dbHost, $dbName, $dbUser, $dbPass);

            // Run composer if needed
            if (file_exists($extractPath . '/composer.json')) {
                $this->migrationService->log("Running composer install...");
                exec('cd ' . base_path() . ' && composer install --no-dev --optimize-autoloader 2>&1', $output, $returnVar);
                $this->migrationService->log("Composer output: " . implode("\n", $output));
            }

            // Run migrations
            $this->migrationService->log("Running Flarum migrations...");
            // Use Artisan safely if available, but be careful as config might have changed
            try {
                \Artisan::call('migrate', ['--force' => true]);
                \Artisan::call('cache:clear');
            } catch (\Throwable $e) {
                $this->migrationService->log("Artisan warning: " . $e->getMessage());
            }

            // Set permissions
            $this->migrationService->log("Setting permissions...");
            chmod(storage_path(), 0775);
            chmod(public_path('assets'), 0775);

            $this->migrationService->log("Migration completed successfully!");

            // Cleanup
            $this->cleanupExtraction($extractPath);

            return new JsonResponse([
                'success' => true,
                'message' => 'Migration completed successfully!'
            ]);

        } catch (\Throwable $e) {
            $this->migrationService->log("Migration error: " . $e->getMessage(), 'error');
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function importDatabase($sqlFile, $host, $dbName, $user, $pass)
    {
        if (!file_exists($sqlFile)) {
            throw new \Exception('Database SQL file not found');
        }

        $sql = file_get_contents($sqlFile);
        
        // Connect to database
        $pdo = new \PDO("mysql:host={$host};dbname={$dbName}", $user, $pass);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Execute SQL
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function($stmt) { return !empty($stmt); }
        );

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }

        $this->migrationService->log("Database imported successfully");
    }

    protected function copyFiles($extractPath)
    {
        // Copy assets
        if (is_dir($extractPath . '/assets')) {
            $this->recursiveCopy($extractPath . '/assets', public_path('assets'));
            $this->migrationService->log("Assets copied");
        }

        // Copy storage
        if (is_dir($extractPath . '/storage')) {
            $this->recursiveCopy($extractPath . '/storage', storage_path());
            $this->migrationService->log("Storage files copied");
        }

        // Copy vendor if exists
        if (is_dir($extractPath . '/vendor')) {
            $this->recursiveCopy($extractPath . '/vendor', base_path('vendor'));
            $this->migrationService->log("Vendor copied");
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
        
        $this->migrationService->log("Config updated with new URL: {$newUrl}");
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

    protected function cleanupExtraction($path)
    {
        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }
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
