<?php

namespace Nauhyuh\MigrationTool\Api\Controller;

use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;
use Nauhyuh\MigrationTool\Service\MigrationService;

class MigrationStatusController extends AbstractShowController
{
    protected $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $uploadPath = storage_path('migration_upload');
        $uploads = [];

        if (is_dir($uploadPath)) {
            $files = scandir($uploadPath);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                    $filePath = $uploadPath . '/' . $file;
                    $uploads[] = [
                        'filename' => $file,
                        'size' => filesize($filePath),
                        'uploaded' => filemtime($filePath)
                    ];
                }
            }
        }

        return [
            'success' => true,
            'uploads' => $uploads,
            'backup_count' => count($this->migrationService->listBackups())
        ];
    }
}
