<?php

namespace Nauhyuh\MigrationTool;

use Flarum\Extend;

return [
    // Console Commands
    (new Extend\Console())
        ->command(Console\ExportCommand::class)
        ->command(Console\ImportCommand::class)
        ->command(Console\MigrateCommand::class),
    
    // API Routes
    (new Extend\Routes('api'))
        // Backup routes
        ->post('/migration/backup/create', 'migration.backup.create', Api\Controller\CreateBackupController::class)
        ->get('/migration/backup/list', 'migration.backup.list', Api\Controller\ListBackupsController::class)
        ->get('/migration/backup/download', 'migration.backup.download', Api\Controller\DownloadBackupController::class)
        ->delete('/migration/backup/delete', 'migration.backup.delete', Api\Controller\DeleteBackupController::class)
        
        // Migration routes
        ->post('/migration/upload', 'migration.upload', Api\Controller\UploadBackupController::class)
        ->post('/migration/migrate', 'migration.migrate', Api\Controller\MigrateController::class)
        ->get('/migration/status', 'migration.status', Api\Controller\MigrationStatusController::class)
        ->get('/migration/logs', 'migration.logs', Api\Controller\GetLogsController::class)
        ->post('/migration/config/update', 'migration.config.update', Api\Controller\UpdateConfigController::class)
        ->post('/migration/cleanup', 'migration.cleanup', Api\Controller\CleanupOldFilesController::class)
        ->post('/migration/progress/reset', 'migration.progress.reset', Api\Controller\ResetProgressController::class),
    
    // Frontend
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),
    
    new Extend\Locales(__DIR__.'/locale'),
];
